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
 * External function to send a message via Zoom Team Chat.
 *
 * @package local_zoomchat
 * @copyright 2026 Jonathan Champ
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomchat\external;

use core\clock;
use core\context\system as context_system;
use core\di;
use core\session\manager as session_manager;
use core\user as core_user;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_zoomchat\helper as zoomchat_helper;
use Throwable;

/**
 * External function to send a message via Zoom Team Chat.
 */
class send_message extends external_api {
    /**
     * Describes the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'recipientid' => new external_value(PARAM_INT, 'Recipient user ID', VALUE_REQUIRED),
            'message' => new external_value(PARAM_RAW, 'Message text (Moodle format)', VALUE_REQUIRED),
            'fileitemids' => new external_value(PARAM_RAW, 'Comma-separated file itemids', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Send a message via Zoom Team Chat.
     *
     * @param int $recipientid Recipient user ID.
     * @param string $message Message text (Moodle format).
     * @param string $fileitemids Comma-separated file itemids.
     * @return array Array with 'success' and optionally 'messageid'.
     */
    public static function execute(int $recipientid, string $message, string $fileitemids = ''): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'recipientid' => $recipientid,
            'message' => $message,
            'fileitemids' => $fileitemids,
        ]);
        $recipientid = $params['recipientid'];
        $messagetext = $params['message'];
        $fileitemids = $params['fileitemids'];

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/zoomchat:chat', $context);

        $messagetext = clean_text($messagetext, FORMAT_MOODLE);
        if (empty($messagetext) && empty($fileitemids)) {
            return ['success' => false];
        }

        $recipient = core_user::get_user($recipientid, '*', MUST_EXIST);

        session_manager::write_close();

        $result = zoomchat_helper::send_message_to_zoom(
            $USER,
            $recipient,
            $messagetext,
            $fileitemids,
            context_system::instance()->id
        );

        if ($result['success']) {
            $notifydata = [
                'messageid' => $result['messageid'],
                'from_userid' => (int) $USER->id,
                'message' => format_text($messagetext, FORMAT_MOODLE, ['context' => context_system::instance()]),
                'timestamp' => di::get(clock::class)->time(),
                'sender_name' => fullname($USER),
            ];
            try {
                zoomchat_helper::notify_user($recipientid, $notifydata);
                zoomchat_helper::notify_user($USER->id, $notifydata);
            } catch (Throwable $e) {
                // Notification failure shouldn't prevent success response.
                $e->getMessage();
            }

            return ['success' => true, 'messageid' => $result['messageid']];
        }

        return ['success' => false, 'error' => $result['error'] ?? ''];
    }

    /**
     * Describes the return structure for execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the message was sent'),
            'messageid' => new external_value(PARAM_INT, 'The new message ID', VALUE_OPTIONAL),
            'error' => new external_value(PARAM_RAW, 'Error message if sending failed', VALUE_OPTIONAL),
        ]);
    }
}
