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
 * External function to get users the current user can chat with.
 *
 * @package    local_zoomchat
 * @copyright  2026 Jonathan Champ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomchat\external;

use core\context\system as context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_zoomchat\helper as zoomchat_helper;
use stdClass;
use tool_zoomapi\helper as zoomapi_helper;

/**
 * External function to get users the current user can chat with.
 */
class get_users extends external_api {
    /**
     * Describes the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID to discover contacts in', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Get users the current user can chat with.
     *
     * @param int $courseid Course ID to discover contacts in.
     * @return array Array with 'users' key.
     */
    public static function execute(int $courseid = 0): array {
        global $USER, $OUTPUT;

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $courseid = $params['courseid'];

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/zoomchat:chat', $context);

        zoomapi_helper::get_userid();

        $builduser = function (stdClass $user): array {
            global $OUTPUT;
            return [
                'id' => (int) $user->id,
                'name' => fullname($user),
                'picture' => $OUTPUT->user_picture($user, ['size' => 36, 'link' => false]),
                'lastmessage' => '',
                'lastmessagetime' => 0,
                'unreadcount' => 0,
            ];
        };

        $conversations = zoomchat_helper::get_conversations_with_latest_message($USER);
        $users = [];
        $seen = [];
        foreach ($conversations as $conv) {
            $partner = $conv['partner'];
            $pid = (int) $partner->id;
            $seen[$pid] = true;
            $users[] = $builduser($partner);
        }

        if ($courseid > 0 && $courseid != SITEID) {
            $coursecontacts = zoomchat_helper::get_course_contacts($USER, $courseid);
            foreach ($coursecontacts as $contact) {
                $cid = (int) $contact->id;
                if (isset($seen[$cid])) {
                    continue;
                }
                $seen[$cid] = true;
                $users[] = $builduser($contact);
            }
        }

        return ['users' => $users];
    }

    /**
     * Describes the return structure for execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'users' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'User ID'),
                    'name' => new external_value(PARAM_TEXT, 'Full name'),
                    'picture' => new external_value(PARAM_RAW, 'User picture HTML'),
                    'lastmessage' => new external_value(PARAM_RAW, 'Last message preview'),
                    'lastmessagetime' => new external_value(PARAM_INT, 'Last message timestamp'),
                    'unreadcount' => new external_value(PARAM_INT, 'Unread message count'),
                ])
            ),
        ]);
    }
}
