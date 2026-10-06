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
 * Data provider for local_zoomchat.
 *
 * @package local_zoomchat
 * @copyright 2026 Jonathan Champ
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomchat\privacy;

use core\context;
use core\context\user as context_user;
use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\provider as metadata_provider;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\helper;
use core_privacy\local\request\plugin\provider as plugin_provider;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_user;
use stdClass;
use Throwable;
use tool_zoomapi\helper as zoomapi_helper;

/**
 * Data provider for local_zoomchat.
 */
class provider implements core_userlist_provider, metadata_provider, plugin_provider {
    /**
     * Returns metadata.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_zoomchat_messages', [
            'from_zoom_id' => 'privacy:metadata:messages:from_zoom_id',
            'to_zoom_id' => 'privacy:metadata:messages:to_zoom_id',
            'channel_id' => 'privacy:metadata:messages:channel_id',
            'message' => 'privacy:metadata:messages:message',
            'timestamp' => 'privacy:metadata:messages:timestamp',
        ], 'privacy:metadata:messages');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();

        $user = core_user::get_user($userid);
        if (!$user) {
            return $contextlist;
        }

        $zoomid = self::get_zoom_id($user);
        if ($zoomid === null) {
            return $contextlist;
        }

        $hasdata = $DB->record_exists_select(
            'local_zoomchat_messages',
            'from_zoom_id = :zoomid1 OR to_zoom_id = :zoomid2',
            ['zoomid1' => $zoomid, 'zoomid2' => $zoomid]
        );
        if ($hasdata) {
            $contextlist->add_user_context($userid);
        }

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof context_user) {
            return;
        }

        $user = core_user::get_user($context->instanceid);
        if (!$user) {
            return;
        }

        $zoomid = self::get_zoom_id($user);
        if ($zoomid === null) {
            return;
        }

        $hasdata = $DB->record_exists_select(
            'local_zoomchat_messages',
            'from_zoom_id = :zoomid1 OR to_zoom_id = :zoomid2',
            ['zoomid1' => $zoomid, 'zoomid2' => $zoomid]
        );
        if ($hasdata) {
            $userlist->add_user($user->id);
        }
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();

        $zoomid = self::get_zoom_id($user);
        if ($zoomid === null) {
            return;
        }

        $recordset = $DB->get_recordset_select(
            'local_zoomchat_messages',
            'from_zoom_id = :zoomid1 OR to_zoom_id = :zoomid2',
            ['zoomid1' => $zoomid, 'zoomid2' => $zoomid],
            'timestamp, id'
        );

        $messages = [];
        foreach ($recordset as $record) {
            $messages[] = [
                'from' => $record->from_zoom_id,
                'to' => $record->to_zoom_id,
                'channel' => $record->channel_id,
                'message' => $record->message,
                'sent_at' => transform::datetime($record->timestamp),
            ];
        }
        $recordset->close();

        if (!empty($messages)) {
            $context = context_user::instance($user->id);
            writer::with_context($context)->export_data(['zoomchat'], (object) ['messages' => $messages]);
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        global $DB;

        if (!$context instanceof context_user) {
            return;
        }

        $user = core_user::get_user($context->instanceid);
        if (!$user) {
            return;
        }

        $zoomid = self::get_zoom_id($user);
        if ($zoomid === null) {
            return;
        }

        $DB->delete_records_select(
            'local_zoomchat_messages',
            'from_zoom_id = :zoomid1 OR to_zoom_id = :zoomid2',
            ['zoomid1' => $zoomid, 'zoomid2' => $zoomid]
        );
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();

        $zoomid = self::get_zoom_id($user);
        if ($zoomid === null) {
            return;
        }

        $DB->delete_records_select(
            'local_zoomchat_messages',
            'from_zoom_id = :zoomid1 OR to_zoom_id = :zoomid2',
            ['zoomid1' => $zoomid, 'zoomid2' => $zoomid]
        );
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $zoomids = [];
        foreach ($userlist->get_userids() as $userid) {
            $user = core_user::get_user($userid);
            if ($user) {
                $zoomid = self::get_zoom_id($user);
                if ($zoomid !== null) {
                    $zoomids[] = $zoomid;
                }
            }
        }

        if (empty($zoomids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($zoomids, SQL_PARAMS_NAMED);
        $DB->delete_records_select('local_zoomchat_messages', "from_zoom_id $insql", $inparams);
        $DB->delete_records_select('local_zoomchat_messages', "to_zoom_id $insql", $inparams);
    }

    /**
     * Resolve the Zoom user ID for a Moodle user.
     *
     * @param stdClass $user The Moodle user.
     * @return string|null
     */
    private static function get_zoom_id(stdClass $user): ?string {
        global $DB;

        $mapped = $DB->get_field('tool_zoomapi_user_mappings', 'zoom_userid', ['userid' => $user->id]);
        if (!empty($mapped)) {
            return (string) $mapped;
        }

        try {
            $zoomuser = zoomapi_helper::get_user(zoomapi_helper::get_api_identifier($user));
        } catch (Throwable $e) {
            debugging('local_zoomchat privacy: failed to resolve Zoom ID: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }

        if (empty($zoomuser) || empty($zoomuser['id'])) {
            return null;
        }

        return (string) $zoomuser['id'];
    }
}
