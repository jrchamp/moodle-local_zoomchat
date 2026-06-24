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
 * Post-install migration for local_zoomchat.
 *
 * Migrates data from the old mod_zoomchat tables if they exist.
 *
 * @package    local_zoomchat
 * @copyright  2026 Jonathan Champ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Post-install hook for local_zoomchat.
 */
function xmldb_local_zoomchat_install() {
    global $DB;
    $dbman = $DB->get_manager();

    $hasoldmessages = $dbman->table_exists('zoomchat_messages');
    $hasoldfilemap = $dbman->table_exists('zoomchat_message_files');

    if ($hasoldmessages) {
        $count = $DB->count_records('zoomchat_messages');
        if ($count > 0) {
            $DB->execute(
                "INSERT INTO {local_zoomchat_messages}
                     (id, from_zoom_id, to_zoom_id, channel_id, message, timestamp, zoom_message_id, deleted)
                 SELECT id, from_zoom_id, to_zoom_id, channel_id, message, timestamp, zoom_message_id, deleted
                   FROM {zoomchat_messages}"
            );
            $dbman->reset_sequence('local_zoomchat_messages');
        }
    }

    if ($hasoldfilemap) {
        $count = $DB->count_records('zoomchat_message_files');
        if ($count > 0) {
            $DB->execute(
                "INSERT INTO {local_zoomchat_message_files}
                     (id, messageid, itemid, filename)
                 SELECT id, messageid, itemid, filename
                   FROM {zoomchat_message_files}"
            );
            $dbman->reset_sequence('local_zoomchat_message_files');
        }
    }
}
