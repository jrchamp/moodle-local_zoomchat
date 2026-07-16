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
 * Helper class for local_zoomchat.
 *
 * @package local_zoomchat
 * @copyright 2026 Jonathan Champ
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomchat;

use core\clock;
use core\context\course as context_course;
use core\context\system as context_system;
use core\di;
use core\exception\moodle_exception;
use stdClass;
use Throwable;
use tool_realtime\channel;
use tool_zoomapi\helper as zoomapi_helper;

/**
 * Static helper for message storage, conversation queries, and Zoom API interaction.
 */
class helper {
    /**
     * Store a message in the local_zoomchat_messages table.
     *
     * @param string $fromzoomid Raw Zoom user ID of the sender.
     * @param string|null $tozoomid Raw Zoom user ID of the recipient.
     * @param string|null $channelid Raw Zoom channel ID for group messages.
     * @param string $messagetext The message content.
     * @param int $timestamp The message timestamp.
     * @param string|null $zoommessageid The Zoom message ID.
     * @return int The message ID.
     */
    public static function store_message(
        string $fromzoomid,
        ?string $tozoomid,
        ?string $channelid,
        string $messagetext,
        int $timestamp,
        ?string $zoommessageid = null
    ): int {
        global $DB;

        $message = new stdClass();
        $message->from_zoom_id = $fromzoomid;
        $message->to_zoom_id = $tozoomid;
        $message->channel_id = $channelid;
        $message->message = $messagetext;
        $message->timestamp = $timestamp;
        $message->zoom_message_id = $zoommessageid;
        $message->deleted = 0;

        return $DB->insert_record('local_zoomchat_messages', $message);
    }

    /**
     * Get all conversation partners for a user with their latest message time.
     *
     * @return array Array of conversation records.
     */
    public static function get_conversations_with_latest_message(): array {
        global $DB;

        $zoomid = zoomapi_helper::get_userid_optional();
        if ($zoomid === null) {
            return [];
        }

        $sql = "SELECT partner_zoom_id, MAX(timestamp) AS lasttime
                  FROM (
                      SELECT CASE WHEN from_zoom_id = :zoomid1 THEN to_zoom_id ELSE from_zoom_id END AS partner_zoom_id,
                             timestamp
                        FROM {local_zoomchat_messages}
                       WHERE (from_zoom_id = :zoomid2 OR to_zoom_id = :zoomid3)
                         AND deleted = 0
                  ) sub
              GROUP BY partner_zoom_id
              ORDER BY lasttime DESC";
        $params = ['zoomid1' => $zoomid, 'zoomid2' => $zoomid, 'zoomid3' => $zoomid];

        $records = $DB->get_records_sql($sql, $params);
        if (empty($records)) {
            return [];
        }

        $conversations = [];
        foreach ($records as $record) {
            if ($record->partner_zoom_id === $zoomid) {
                continue;
            }

            $partnerzoom = zoomapi_helper::get_user($record->partner_zoom_id);
            if (empty($partnerzoom) || empty($partnerzoom['email'])) {
                continue;
            }

            $moodleuser = $DB->get_record(
                'user',
                ['email' => $partnerzoom['email'], 'deleted' => 0]
            );
            if (!$moodleuser) {
                continue;
            }

            $conversations[] = [
                'partner' => $moodleuser,
                'partner_zoom_id' => $record->partner_zoom_id,
                'lasttime' => $record->lasttime,
            ];
        }

        return $conversations;
    }

    /**
     * Get course contacts the current user can start conversations with.
     *
     * Teachers (moodle/course:update) can message anyone enrolled.
     * Students can only message editing teachers.
     *
     * @param \stdClass $user The current user.
     * @param int $courseid The course ID.
     * @return array Array of user records (those with Zoom accounts).
     */
    public static function get_course_contacts(stdClass $user, int $courseid): array {
        if ($courseid <= 0) {
            return [];
        }

        $context = context_course::instance($courseid);
        $iseditor = has_capability('moodle/course:update', $context, $user);

        $enrolled = get_enrolled_users($context, $iseditor ? '' : 'moodle/course:update');

        // Remove self from the list.
        unset($enrolled[$user->id]);

        if (empty($enrolled)) {
            return [];
        }

        $contacts = [];
        foreach ($enrolled as $candidate) {
            try {
                $zoomuser = zoomapi_helper::get_user(zoomapi_helper::get_api_identifier($candidate));
                if (!empty($zoomuser) && !empty($zoomuser['email'])) {
                    $contacts[] = $candidate;
                }
            } catch (Throwable $e) {
                continue;
            }
        }

        return $contacts;
    }

    /**
     * Get messages for a specific Zoom conversation between two users.
     *
     * @param \stdClass $user The Moodle user.
     * @param string $partnerzoomid The Zoom ID of the conversation partner.
     * @param int $limitfrom Offset for pagination.
     * @param int $limitnum Number of messages to return.
     * @return array Array of message records.
     */
    public static function get_zoom_conversation_messages(
        stdClass $user,
        string $partnerzoomid,
        int $limitfrom = 0,
        int $limitnum = 0
    ): array {
        global $DB;

        $zoomuser = zoomapi_helper::get_user(zoomapi_helper::get_api_identifier($user));
        if (empty($zoomuser) || empty($zoomuser['id'])) {
            return [];
        }

        $zoomid = $zoomuser['id'];

        $sql = "SELECT *
                  FROM {local_zoomchat_messages}
                 WHERE (from_zoom_id = :zoomid1 AND to_zoom_id = :partner1)
                    OR (from_zoom_id = :partner2 AND to_zoom_id = :zoomid2)
              ORDER BY timestamp DESC";
        $params = [
            'zoomid1' => $zoomid,
            'partner1' => $partnerzoomid,
            'zoomid2' => $zoomid,
            'partner2' => $partnerzoomid,
        ];

        $records = $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);
        return array_reverse($records);
    }

    /**
     * Send a direct message via Zoom Team Chat, optionally with file attachments.
     *
     * @param \stdClass $sender The Moodle user sending the message.
     * @param \stdClass $recipient The Moodle user to message.
     * @param string $messagetext The message content (may contain <img> tags).
     * @param string $fileitemids Comma-separated Moodle file itemids to attach.
     * @param int $contextid The context ID where attachments are stored.
     * @return array Array with 'success' bool and optionally 'error' string and 'messageid' int.
     */
    public static function send_message_to_zoom(
        stdClass $sender,
        stdClass $recipient,
        string $messagetext,
        string $fileitemids = '',
        int $contextid = 0
    ): array {
        $senderzoom = zoomapi_helper::get_user(zoomapi_helper::get_api_identifier($sender));
        if (empty($senderzoom) || empty($senderzoom['id'])) {
            debugging('Sender has no Zoom account', DEBUG_DEVELOPER);
            return ['success' => false, 'error' => get_string('error:nosenderzoom', 'local_zoomchat')];
        }

        $recipientzoom = zoomapi_helper::get_user(zoomapi_helper::get_api_identifier($recipient));
        if (empty($recipientzoom) || empty($recipientzoom['email'])) {
            debugging('Recipient has no Zoom account', DEBUG_DEVELOPER);
            return ['success' => false, 'error' => get_string('error:norecipientzoom', 'local_zoomchat')];
        }

        $zoomfileids = self::upload_files_to_zoom($senderzoom['id'], $fileitemids, $contextid);

        $zoomtext = preg_replace('/<img[^>]*>/i', '', $messagetext);
        $zoomtext = trim($zoomtext);

        $zoompayload = [
            'to_contact' => $recipientzoom['email'],
            'message' => $zoomtext,
        ];
        if (!empty($zoomfileids)) {
            $zoompayload['file_ids'] = $zoomfileids;
        }

        try {
            $zoomresponse = api::instance()->create_user_message($senderzoom['id'], $zoompayload);
        } catch (moodle_exception $e) {
            debugging('Failed to send Zoom DM: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $zoommessageid = $zoomresponse['id'] ?? null;

        $messageid = self::store_message(
            $senderzoom['id'],
            $recipientzoom['id'],
            null,
            $messagetext,
            di::get(clock::class)->time(),
            $zoommessageid
        );

        self::record_file_links($messageid, $fileitemids, $contextid);

        return ['success' => true, 'messageid' => $messageid];
    }

    /**
     * Upload file attachments to Zoom and return their Zoom file IDs.
     *
     * @param string $zoomuserid The sender's Zoom user ID.
     * @param string $fileitemids Comma-separated Moodle file itemids.
     * @param int $contextid The context ID.
     * @return array Array of Zoom file IDs.
     */
    private static function upload_files_to_zoom(string $zoomuserid, string $fileitemids, int $contextid): array {
        $zoomfileids = [];
        if (empty($fileitemids) || $contextid <= 0) {
            return $zoomfileids;
        }
        $ids = explode(',', $fileitemids);
        foreach ($ids as $itemid) {
            $itemid = (int) trim($itemid);
            if ($itemid <= 0) {
                continue;
            }
            $zoomid = self::upload_attachment_to_zoom($zoomuserid, $itemid, $contextid);
            if ($zoomid !== null) {
                $zoomfileids[] = $zoomid;
            }
        }
        return $zoomfileids;
    }

    /**
     * Upload a single attachment to Zoom and return the Zoom file ID.
     *
     * @param string $zoomuserid The sender's Zoom user ID.
     * @param int $itemid The Moodle file itemid.
     * @param int $contextid The context ID.
     * @return string|null The Zoom file ID, or null on failure.
     */
    private static function upload_attachment_to_zoom(
        string $zoomuserid,
        int $itemid,
        int $contextid
    ): ?string {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $contextid,
            'local_zoomchat',
            'attachment',
            $itemid,
            'filename ASC',
            false
        );

        if (empty($files)) {
            debugging('No attachment found for context ' . $contextid . ' itemid ' . $itemid, DEBUG_DEVELOPER);
            return null;
        }

        $file = reset($files);
        $temppath = make_temp_directory('zoomchat_uploads') . '/' . $file->get_filename();
        $file->copy_content_to($temppath);

        try {
            $zoomfileid = api::instance()->upload_chat_file(
                $zoomuserid,
                $temppath,
                $file->get_filename()
            );
            unlink($temppath);
            return $zoomfileid;
        } catch (moodle_exception $e) {
            @unlink($temppath);
            debugging('Failed to upload file to Zoom: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Record file-to-message associations in the local_zoomchat_message_files table.
     *
     * @param int $messageid The local message ID.
     * @param string $fileitemids Comma-separated Moodle file itemids.
     * @param int $contextid The context ID.
     * @return void
     */
    private static function record_file_links(int $messageid, string $fileitemids, int $contextid): void {
        global $DB;
        if (empty($fileitemids) || $contextid <= 0) {
            return;
        }
        $ids = explode(',', $fileitemids);
        foreach ($ids as $itemid) {
            $itemid = (int) trim($itemid);
            if ($itemid <= 0) {
                continue;
            }
            $fs = get_file_storage();
            $files = $fs->get_area_files($contextid, 'local_zoomchat', 'attachment', $itemid, 'filename ASC', false);
            foreach ($files as $file) {
                $record = new stdClass();
                $record->messageid = $messageid;
                $record->itemid = $itemid;
                $record->filename = $file->get_filename();
                $DB->insert_record('local_zoomchat_message_files', $record);
            }
        }
    }

    /**
     * Publish a realtime notification to a specific user's channel.
     *
     * @param int $userid The Moodle user ID to notify.
     * @param array $data The payload data to send.
     * @return void
     */
    public static function notify_user(int $userid, array $data): void {
        $channel = new channel(
            context_system::instance(),
            'local_zoomchat',
            'zoomchat',
            0,
            json_encode(['userid' => (int) $userid])
        );
        $channel->notify($data);
    }

    /**
     * Get a message record by its Zoom message ID.
     *
     * @param string $zoommessageid The Zoom message ID.
     * @return \stdClass|null The message record or null.
     */
    public static function get_message_by_zoom_id(string $zoommessageid): ?stdClass {
        global $DB;

        $record = $DB->get_record('local_zoomchat_messages', ['zoom_message_id' => $zoommessageid]);
        return $record ?: null;
    }

    /**
     * Receive a message from Zoom Team Chat and store it with raw Zoom IDs.
     *
     * Avoids duplicates: if the Zoom message ID already exists, returns the existing record ID.
     *
     * @param string $fromzoomid The Zoom user ID of the sender.
     * @param string|null $tozoomid The Zoom user ID of the recipient.
     * @param string|null $channelid The Zoom channel ID for group messages.
     * @param string $messagetext The message content.
     * @param int $timestamp The message timestamp.
     * @param string|null $zoommessageid The Zoom message ID for update/delete tracking.
     * @return int The inserted message ID.
     */
    public static function receive_message_from_zoom(
        string $fromzoomid,
        ?string $tozoomid,
        ?string $channelid,
        string $messagetext,
        int $timestamp,
        ?string $zoommessageid = null
    ): array {
        if ($zoommessageid !== null) {
            $existing = self::get_message_by_zoom_id($zoommessageid);
            if ($existing !== null) {
                return ['messageid' => (int) $existing->id, 'new' => false];
            }
        }

        $messageid = self::store_message(
            $fromzoomid,
            $tozoomid,
            $channelid,
            $messagetext,
            $timestamp,
            $zoommessageid
        );
        return ['messageid' => $messageid, 'new' => true];
    }

    /**
     * Update a message's text by its Zoom message ID.
     *
     * @param string $zoommessageid The Zoom message ID.
     * @param string $newtext The updated message text.
     * @param int $timestamp The update timestamp.
     * @return bool True if the message was updated.
     */
    public static function update_message_by_zoom_id(string $zoommessageid, string $newtext, int $timestamp): bool {
        global $DB;

        $record = $DB->get_record('local_zoomchat_messages', ['zoom_message_id' => $zoommessageid]);
        if (!$record) {
            return false;
        }

        $record->message = $newtext;
        $record->timestamp = $timestamp;
        return $DB->update_record('local_zoomchat_messages', $record);
    }

    /**
     * Mark a message as deleted by its Zoom message ID.
     *
     * @param string $zoommessageid The Zoom message ID.
     * @return bool True if the message was marked deleted.
     */
    public static function mark_message_deleted_by_zoom_id(string $zoommessageid): bool {
        global $DB;

        $record = $DB->get_record('local_zoomchat_messages', ['zoom_message_id' => $zoommessageid]);
        if (!$record) {
            return false;
        }

        $record->deleted = 1;
        return $DB->update_record('local_zoomchat_messages', $record);
    }
}
