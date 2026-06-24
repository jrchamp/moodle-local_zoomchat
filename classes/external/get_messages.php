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
 * External function to get messages for a conversation.
 *
 * @package    local_zoomchat
 * @copyright  2026 Jonathan Champ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomchat\external;

use core\context\system as context_system;
use core\user as core_user;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_zoomchat\helper as zoomchat_helper;
use tool_zoomapi\helper as zoomapi_helper;

/**
 * External function to get messages for a conversation.
 */
class get_messages extends external_api {
    /**
     * Describes the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'partnerid' => new external_value(PARAM_INT, 'The other user\'s Moodle ID', VALUE_REQUIRED),
            'lasttime' => new external_value(PARAM_INT, 'Only return messages after this timestamp', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Get messages for a conversation with another user.
     *
     * @param int $partnerid The other user's Moodle ID.
     * @param int $lasttime Only return messages after this timestamp.
     * @return array Array with 'messages' key.
     */
    public static function execute(int $partnerid, int $lasttime = 0): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'partnerid' => $partnerid,
            'lasttime' => $lasttime,
        ]);
        $partnerid = $params['partnerid'];
        $lasttime = $params['lasttime'];

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/zoomchat:chat', $context);

        $zoomuserid = zoomapi_helper::get_userid();

        $partneruser = core_user::get_user($partnerid, '*', MUST_EXIST);
        $partnerzoomid = zoomapi_helper::get_zoom_userid($partneruser);

        $messages = zoomchat_helper::get_zoom_conversation_messages($USER, $partnerzoomid, 0, 50);

        $formatted = [];
        foreach ($messages as $msg) {
            if ($lasttime && $msg->timestamp <= $lasttime) {
                continue;
            }
            $formatted[] = [
                'id' => (int) $msg->id,
                'message' => format_text($msg->message, FORMAT_MOODLE, ['context' => context_system::instance()]),
                'timestamp' => (int) $msg->timestamp,
                'mymessage' => ($msg->from_zoom_id === $zoomuserid),
            ];
        }

        return ['messages' => $formatted];
    }

    /**
     * Describes the return structure for execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'messages' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Message ID'),
                    'message' => new external_value(PARAM_RAW, 'Message content (HTML)'),
                    'timestamp' => new external_value(PARAM_INT, 'Message timestamp'),
                    'mymessage' => new external_value(PARAM_BOOL, 'Whether this message was sent by the current user'),
                ])
            ),
        ]);
    }
}
