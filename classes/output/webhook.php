<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Handles incoming webhooks from Zoom Team Chat API for local_zoomchat.
 *
 * @package local_zoomchat
 * @copyright 2026 Jonathan Champ
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomchat\output;

use core\clock;
use core\context\system as context_system;
use core\di;
use local_zoomchat\api;
use local_zoomchat\helper;
use moodle_url;
use stdClass;
use Throwable;
use tool_zoomapi\helper as zoomapi_helper;

/**
 * Handles incoming webhooks from Zoom Team Chat API.
 *
 * Stores incoming messages with raw Zoom IDs only. No Moodle user or chat
 * resolution is performed at webhook time. Conversation resolution happens
 * at display time using tool_zoomapi cache.
 */
class webhook {
    /**
     * Handle the incoming Zoom webhook request.
     */
    public static function handle_request(): void {
        $rawbody = file_get_contents('php://input');
        $payload = json_decode($rawbody, true, 512);

        if (!$payload) {
            self::respond(400, ['error' => 'Invalid JSON payload']);
            return;
        }

        if (isset($payload['event']) && $payload['event'] === 'endpoint.url_validation') {
            self::handle_validation_challenge($payload);
            return;
        }

        if (!self::validate_signature($rawbody)) {
            self::respond(401, ['error' => 'Invalid signature']);
            return;
        }

        self::dispatch_event($payload);

        self::respond(200, ['status' => 'ok']);
    }

    /**
     * Dispatch the webhook event to the appropriate handler.
     *
     * @param array $payload The webhook payload.
     */
    private static function dispatch_event(array $payload): void {
        $event = $payload['event'] ?? '';
        switch ($event) {
            case 'chat_message.sent':
                self::handle_message_sent($payload);
                break;

            case 'chat_message.updated':
                self::handle_message_updated($payload);
                break;

            case 'chat_message.deleted':
                self::handle_message_deleted($payload);
                break;

            case 'team_chat.file_shared':
                self::handle_file_shared($payload);
                break;

            case 'team_chat.file_deleted':
            case 'team_chat.file_unshared':
                break;

            case 'team_chat.dm_message_posted':
                self::handle_dm_message_posted($payload);
                break;

            case 'team_chat.dm_message_updated':
                self::handle_dm_message_updated($payload);
                break;

            case 'team_chat.dm_message_deleted':
                self::handle_dm_message_deleted($payload);
                break;

            case 'team_chat.channel_message_posted':
                self::handle_channel_message_posted($payload);
                break;

            case 'team_chat.channel_message_updated':
                self::handle_channel_message_updated($payload);
                break;

            case 'team_chat.channel_message_deleted':
                self::handle_channel_message_deleted($payload);
                break;

            case 'team_chat.app_mention':
                self::handle_app_mention($payload);
                break;

            case 'team_chat.link_shared':
                self::handle_link_shared($payload);
                break;

            default:
                self::handle_unknown_event($payload);
                break;
        }
    }

    /**
     * Send an HTTP JSON response.
     *
     * @param int $statuscode
     * @param array $data
     */
    private static function respond(int $statuscode, array $data): void {
        http_response_code($statuscode);
        echo json_encode($data);
    }

    /**
     * Get the webhook secret from local_zoomchat config.
     *
     * @return string The webhook secret, or empty string.
     */
    protected static function get_webhook_secret(): string {
        return (string) get_config('local_zoomchat', 'webhook_secret');
    }

    /**
     * Handle webhook validation challenge.
     *
     * @param array $payload The validation payload.
     * @return void
     */
    protected static function handle_validation_challenge(array $payload): void {
        $plaintoken = $payload['payload']['plainToken'] ?? '';

        if (empty($plaintoken)) {
            self::respond(400, ['error' => 'Missing plainToken']);
            return;
        }

        $secret = self::get_webhook_secret();
        if (empty($secret)) {
            debugging('Zoom webhook secret not configured for validation', DEBUG_DEVELOPER);
            self::respond(500, ['error' => 'Webhook secret not configured']);
            return;
        }

        self::respond(200, [
            'plainToken' => $plaintoken,
            'encryptedToken' => hash_hmac('sha256', $plaintoken, $secret),
        ]);
    }

    /**
     * Validate the webhook signature.
     *
     * @param string $rawbody The raw request body.
     * @return bool True if the signature is valid, false otherwise.
     */
    protected static function validate_signature(string $rawbody): bool {
        $secret = self::get_webhook_secret();

        if (empty($secret)) {
            debugging('Zoom webhook secret not configured', DEBUG_DEVELOPER);
            return false;
        }

        $signature = $_SERVER['HTTP_X_ZM_SIGNATURE'] ?? '';
        if (empty($signature)) {
            return false;
        }

        $timestamp = $_SERVER['HTTP_X_ZM_REQUEST_TIMESTAMP'] ?? '';
        if (empty($timestamp)) {
            return false;
        }

        if (di::get(clock::class)->time() - (int) $timestamp > 300) {
            return false;
        }

        return hash_equals(
            "v0=" . hash_hmac('sha256', "v0:$timestamp:$rawbody", $secret),
            $signature
        );
    }

    /**
     * Handle incoming message from Zoom Team Chat.
     *
     * @param array $payload The webhook payload.
     * @return void
     */
    protected static function handle_message_sent(array $payload): void {
        $fromzoomid = self::get_operator_id($payload);
        if (empty($fromzoomid)) {
            return;
        }

        $object = $payload['payload']['object'] ?? [];
        $messagetext = $object['message'] ?? '';
        $zoommessageid = $object['id'] ?? null;
        $timestamp = self::extract_timestamp($payload);

        $tozoomid = null;
        $channelid = null;
        if (($object['type'] ?? '') === 'to_contact') {
            $tozoomid = $object['contact_id'] ?? null;
        } else {
            $channelid = $object['channel_id'] ?? null;
        }

        $result = helper::receive_message_from_zoom(
            $fromzoomid,
            $tozoomid,
            $channelid,
            $messagetext,
            $timestamp,
            $zoommessageid
        );

        if ($result['messageid'] && $tozoomid !== null && $result['new']) {
            $sanitized = format_text($messagetext, FORMAT_MOODLE, ['context' => context_system::instance()]);
            self::publish_notification($fromzoomid, $tozoomid, $result['messageid'], $sanitized, $timestamp);
        }
    }

    /**
     * Handle file shared event from Zoom Team Chat.
     *
     * @param array $payload The webhook payload.
     */
    protected static function handle_file_shared(array $payload): void {
        $fromzoomid = self::get_operator_id($payload);
        if (empty($fromzoomid)) {
            return;
        }

        $object = $payload['payload']['object'] ?? [];
        $files = $object['files'] ?? [];
        if (empty($files)) {
            return;
        }

        $tozoomid = null;
        $channelid = null;
        if (($object['type'] ?? '') === 'to_contact') {
            $tozoomid = $object['contact_id'] ?? null;
        } else {
            $channelid = $object['channel_id'] ?? null;
        }

        $zoommessageid = $object['message_id'] ?? null;
        $timestamp = self::extract_timestamp($payload);
        $result = self::download_and_store_files(
            $files,
            $fromzoomid,
            $tozoomid,
            $channelid,
            $timestamp,
            $zoommessageid
        );
        if ($result && $result['messageid'] && $tozoomid !== null) {
            $sanitized = format_text($result['filehtml'], FORMAT_MOODLE, ['context' => context_system::instance()]);
            self::publish_notification($fromzoomid, $tozoomid, $result['messageid'], $sanitized, $timestamp);
        }
    }

    /**
     * Handle V1 DM message posted event (message + inline files).
     *
     * @param array $payload The webhook payload.
     */
    protected static function handle_dm_message_posted(array $payload): void {
        $fromzoomid = self::get_operator_id($payload);
        if (empty($fromzoomid)) {
            return;
        }

        $object = $payload['payload']['object'] ?? [];
        $messagetext = $object['message'] ?? '';
        $zoommessageid = $object['message_id'] ?? null;
        $tozoomid = $object['contact_id'] ?? null;
        $timestamp = self::extract_timestamp($payload);
        $inlinefiles = $object['files'] ?? [];

        if (empty($inlinefiles)) {
            $result = helper::receive_message_from_zoom(
                $fromzoomid,
                $tozoomid,
                null,
                $messagetext,
                $timestamp,
                $zoommessageid
            );
            if ($result['messageid'] && $tozoomid !== null && $result['new']) {
                $sanitizedtext = format_text($messagetext, FORMAT_MOODLE, ['context' => context_system::instance()]);
                self::publish_notification($fromzoomid, $tozoomid, $result['messageid'], $sanitizedtext, $timestamp);
            }
            return;
        }

        $result = self::download_and_store_files(
            $inlinefiles,
            $fromzoomid,
            $tozoomid,
            null,
            $timestamp,
            $zoommessageid,
            $messagetext
        );
        if ($result && $tozoomid !== null && $result['new']) {
            $sanitized = format_text($result['filehtml'], FORMAT_MOODLE, ['context' => context_system::instance()]);
            self::publish_notification($fromzoomid, $tozoomid, $result['messageid'], $sanitized, $timestamp);
        }
    }

    /**
     * Handle V1 channel message posted event (message + inline files).
     *
     * @param array $payload The webhook payload.
     */
    protected static function handle_channel_message_posted(array $payload): void {
        $fromzoomid = self::get_operator_id($payload);
        if (empty($fromzoomid)) {
            return;
        }

        $object = $payload['payload']['object'] ?? [];
        $messagetext = $object['message'] ?? '';
        $zoommessageid = $object['message_id'] ?? null;
        $channelid = $object['channel_id'] ?? null;
        $timestamp = self::extract_timestamp($payload);
        $inlinefiles = $object['files'] ?? [];

        if (empty($inlinefiles)) {
            helper::receive_message_from_zoom(
                $fromzoomid,
                null,
                $channelid,
                $messagetext,
                $timestamp,
                $zoommessageid
            );
            return;
        }

        self::download_and_store_files(
            $inlinefiles,
            $fromzoomid,
            null,
            $channelid,
            $timestamp,
            $zoommessageid,
            $messagetext
        );
    }

    /**
     * Handle V2 message updated event.
     *
     * @param array $payload The webhook payload.
     */
    protected static function handle_message_updated(array $payload): void {
        $object = $payload['payload']['object'] ?? [];
        $zoommessageid = $object['id'] ?? null;
        if (empty($zoommessageid)) {
            return;
        }

        $updated = helper::update_message_by_zoom_id(
            $zoommessageid,
            $object['message'] ?? '',
            self::extract_timestamp($payload)
        );
        if (!$updated) {
            debugging("Zoom webhook: message_updated — no local message found for id {$zoommessageid}", DEBUG_DEVELOPER);
        }
    }

    /**
     * Handle V2 message deleted event.
     *
     * @param array $payload The webhook payload.
     */
    protected static function handle_message_deleted(array $payload): void {
        $zoommessageid = $payload['payload']['object']['id'] ?? null;
        if (empty($zoommessageid)) {
            return;
        }

        $deleted = helper::mark_message_deleted_by_zoom_id($zoommessageid);
        if (!$deleted) {
            debugging("Zoom webhook: message_deleted — no local message found for id {$zoommessageid}", DEBUG_DEVELOPER);
        }
    }

    /**
     * Handle V1 DM message updated event.
     *
     * @param array $payload The webhook payload.
     */
    protected static function handle_dm_message_updated(array $payload): void {
        $object = $payload['payload']['object'] ?? [];
        $zoommessageid = $object['message_id'] ?? null;
        if (empty($zoommessageid)) {
            return;
        }

        $newtext = $object['message'] ?? '';
        $timestamp = self::extract_timestamp($payload);

        $updated = helper::update_message_by_zoom_id($zoommessageid, $newtext, $timestamp);
        if (!$updated) {
            debugging("Zoom webhook: dm_message_updated — no local message found for id {$zoommessageid}", DEBUG_DEVELOPER);
            return;
        }

        $stored = helper::get_message_by_zoom_id($zoommessageid);
        if ($stored && $stored->to_zoom_id) {
            $sanitized = format_text($newtext, FORMAT_MOODLE, ['context' => context_system::instance()]);
            self::publish_notification(
                $stored->from_zoom_id,
                $stored->to_zoom_id,
                (int) $stored->id,
                $sanitized,
                $timestamp
            );
        }
    }

    /**
     * Handle V1 channel message updated event.
     *
     * @param array $payload The webhook payload.
     */
    protected static function handle_channel_message_updated(array $payload): void {
        $object = $payload['payload']['object'] ?? [];
        $zoommessageid = $object['message_id'] ?? null;
        if (empty($zoommessageid)) {
            return;
        }

        $newtext = $object['message'] ?? '';
        $timestamp = self::extract_timestamp($payload);

        $updated = helper::update_message_by_zoom_id($zoommessageid, $newtext, $timestamp);
        if (!$updated) {
            debugging("Zoom webhook: channel_message_updated — no local message found for id {$zoommessageid}", DEBUG_DEVELOPER);
            return;
        }

        $stored = helper::get_message_by_zoom_id($zoommessageid);
        if ($stored && $stored->to_zoom_id) {
            $sanitized = format_text($newtext, FORMAT_MOODLE, ['context' => context_system::instance()]);
            self::publish_notification(
                $stored->from_zoom_id,
                $stored->to_zoom_id,
                (int) $stored->id,
                $sanitized,
                $timestamp
            );
        }
    }

    /**
     * Handle V1 DM message deleted event.
     *
     * @param array $payload The webhook payload.
     */
    protected static function handle_dm_message_deleted(array $payload): void {
        $zoommessageid = $payload['payload']['object']['message_id'] ?? null;
        if (empty($zoommessageid)) {
            return;
        }

        $deleted = helper::mark_message_deleted_by_zoom_id($zoommessageid);
        if (!$deleted) {
            debugging("Zoom webhook: dm_message_deleted — no local message found for id {$zoommessageid}", DEBUG_DEVELOPER);
        }
    }

    /**
     * Handle V1 channel message deleted event.
     *
     * @param array $payload The webhook payload.
     */
    protected static function handle_channel_message_deleted(array $payload): void {
        $zoommessageid = $payload['payload']['object']['message_id'] ?? null;
        if (empty($zoommessageid)) {
            return;
        }

        $deleted = helper::mark_message_deleted_by_zoom_id($zoommessageid);
        if (!$deleted) {
            debugging("Zoom webhook: channel_message_deleted — no local message found for id {$zoommessageid}", DEBUG_DEVELOPER);
        }
    }

    /**
     * Extract the operator_id from a webhook payload.
     *
     * @param array $payload The webhook payload.
     * @return string The operator ID, or empty string.
     */
    protected static function get_operator_id(array $payload): string {
        return $payload['payload']['operator_id'] ?? '';
    }

    /**
     * Extract the timestamp from a webhook payload in seconds.
     *
     * @param array $payload The webhook payload.
     * @return int The timestamp in seconds.
     */
    protected static function extract_timestamp(array $payload): int {
        $timestampms = (int) ($payload['event_ts'] ?? $payload['payload']['object']['timestamp'] ?? 0);
        return (int) ($timestampms / 1000);
    }

    /**
     * Download files from Zoom, store them in Moodle's file API, build HTML.
     *
     * @param array $files Array of file data from the payload.
     * @param string $fromzoomid Sender's Zoom ID.
     * @param string|null $tozoomid Recipient's Zoom ID (null for channels).
     * @param string|null $channelid Channel ID (null for DMs).
     * @param int $timestamp Message timestamp in seconds.
     * @param string|null $zoommessageid Zoom message ID.
     * @param string $messagetext Optional text to prepend before file HTML.
     * @return array|null Array with 'messageid', 'filehtml' and 'new', or null on failure.
     */
    protected static function download_and_store_files(
        array $files,
        string $fromzoomid,
        ?string $tozoomid,
        ?string $channelid,
        int $timestamp,
        ?string $zoommessageid = null,
        string $messagetext = ''
    ): ?array {
        global $DB;

        $zoomapi = api::instance();
        $context = context_system::instance();
        $fs = get_file_storage();

        $filehtml = '';
        $fileitemids = [];

        foreach ($files as $filedata) {
            $fileid = $filedata['file_id'] ?? '';
            $filename = $filedata['file_name'] ?? 'unknown';
            $filetype = $filedata['OS_file_type'] ?? '';

            if (empty($fileid)) {
                continue;
            }

            try {
                $filedownload = $zoomapi->download_chat_file($fileid);
                $content = $filedownload['content'];
                $filename = $filedownload['filename'] ?: $filename;
            } catch (Throwable $e) {
                debugging("Zoom webhook: failed to download file {$fileid}: " . $e->getMessage(), DEBUG_DEVELOPER);
                continue;
            }

            $itemid = random_int(0, 2147483647);
            $cleanname = clean_filename($filename);

            $filerecord = [
                'contextid' => $context->id,
                'component' => 'local_zoomchat',
                'filearea' => 'webhook_attachment',
                'itemid' => $itemid,
                'filepath' => '/',
                'filename' => $cleanname,
            ];

            try {
                $fs->create_file_from_string($filerecord, $content);
            } catch (Throwable $e) {
                debugging("Zoom webhook: failed to store file {$cleanname}: " . $e->getMessage(), DEBUG_DEVELOPER);
                continue;
            }

            $url = moodle_url::make_pluginfile_url(
                $context->id,
                'local_zoomchat',
                'webhook_attachment',
                $itemid,
                '/',
                $cleanname
            );

            if ($filetype === 'image' || preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $cleanname)) {
                $filehtml .= '<img src="' . $url->out() . '" alt="' . s($cleanname) . '">';
            } else {
                $filehtml .= '<a href="' . $url->out() . '" target="_blank">' . s($cleanname) . '</a>';
            }

            $fileitemids[] = $itemid;
        }

        if (empty($filehtml)) {
            return null;
        }

        if ($messagetext !== '') {
            $filehtml = $messagetext . $filehtml;
        }

        $stored = helper::receive_message_from_zoom(
            $fromzoomid,
            $tozoomid,
            $channelid,
            $filehtml,
            $timestamp,
            $zoommessageid
        );
        if (!$stored['messageid']) {
            return null;
        }

        if (!$stored['new'] && $filehtml !== '') {
            $existing = $DB->get_record('local_zoomchat_messages', ['id' => $stored['messageid']]);
            if ($existing && strpos($existing->message, $filehtml) === false) {
                $existing->message .= $filehtml;
                $DB->update_record('local_zoomchat_messages', $existing);
            }
        }

        foreach ($fileitemids as $itemid) {
            $filerecs = $fs->get_area_files(
                $context->id,
                'local_zoomchat',
                'webhook_attachment',
                $itemid,
                'filename ASC',
                false
            );
            foreach ($filerecs as $file) {
                $record = new stdClass();
                $record->messageid = $stored['messageid'];
                $record->itemid = $itemid;
                $record->filename = $file->get_filename();
                $DB->insert_record('local_zoomchat_message_files', $record);
            }
        }

        return ['messageid' => $stored['messageid'], 'filehtml' => $filehtml, 'new' => $stored['new']];
    }

    /**
     * Publish a realtime notification for a message.
     *
     * @param string $fromzoomid The sender's Zoom user ID.
     * @param string $tozoomid The recipient's Zoom user ID or email.
     * @param int $messageid The local message ID.
     * @param string $messagetext The message text (with embedded file HTML).
     * @param int $timestamp The message timestamp.
     */
    private static function publish_notification(
        string $fromzoomid,
        string $tozoomid,
        int $messageid,
        string $messagetext,
        int $timestamp
    ): void {
        global $DB;

        $recipientzoom = zoomapi_helper::get_user($tozoomid);
        if (empty($recipientzoom) || empty($recipientzoom['email'])) {
            return;
        }
        $recipientuser = $DB->get_record('user', ['email' => $recipientzoom['email'], 'deleted' => 0], 'id');
        if (!$recipientuser) {
            return;
        }

        $senderuserid = 0;
        $senderzoom = zoomapi_helper::get_user($fromzoomid);
        if (!empty($senderzoom) && !empty($senderzoom['email'])) {
            $senderuser = $DB->get_record('user', ['email' => $senderzoom['email'], 'deleted' => 0]);
            $sendername = $senderuser ? fullname($senderuser) : ($senderzoom['first_name'] . ' ' . $senderzoom['last_name']);
            $senderuserid = $senderuser->id ?? 0;
        } else {
            $sendername = get_string('unknownuser', 'local_zoomchat');
        }

        $senderid = (int) $senderuserid;
        $recipientid = (int) $recipientuser->id;
        $pubdata = [
            'messageid' => (int) $messageid,
            'from_userid' => $senderid,
            'message' => $messagetext,
            'timestamp' => (int) $timestamp,
            'sender_name' => $sendername,
        ];

        helper::notify_user($recipientid, $pubdata);
        if ($senderid > 0) {
            helper::notify_user($senderid, $pubdata);
        }
    }

    /**
     * Handle app mention event from Zoom Team Chat.
     *
     * @param array $payload The webhook payload.
     * @return void
     */
    protected static function handle_app_mention(array $payload): void {
        $operator = $payload['payload']['operator'] ?? 'unknown';
        debugging('Zoom Team Chat app mention from: ' . $operator, DEBUG_DEVELOPER);
    }

    /**
     * Handle link shared event from Zoom Team Chat.
     *
     * @param array $payload The webhook payload.
     * @return void
     */
    protected static function handle_link_shared(array $payload): void {
        $link = $payload['payload']['object']['link'] ?? '';
        debugging('Zoom Team Chat link shared: ' . $link, DEBUG_DEVELOPER);
    }

    /**
     * Handle unknown event types.
     *
     * @param array $payload The webhook payload.
     * @return void
     */
    protected static function handle_unknown_event(array $payload): void {
        $event = $payload['event'] ?? 'unknown';
        debugging("Unknown Zoom Team Chat event received: $event", DEBUG_DEVELOPER);
    }
}
