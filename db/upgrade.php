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
 * Upgrade steps for local_zoomchat.
 *
 * @package local_zoomchat
 * @copyright 2026 Jonathan Champ
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade function for local_zoomchat.
 *
 * @param int $oldversion The version being upgraded from.
 * @return bool
 */
function xmldb_local_zoomchat_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026071701) {
        $table = new xmldb_table('local_zoomchat_messages');

        $duplicates = $DB->get_recordset_sql(
            "SELECT MIN(id) AS keepid, zoom_message_id
               FROM {local_zoomchat_messages}
              WHERE zoom_message_id IS NOT NULL
           GROUP BY zoom_message_id
             HAVING COUNT(*) > 1"
        );
        foreach ($duplicates as $dup) {
            $DB->delete_records_select(
                'local_zoomchat_messages',
                'zoom_message_id = ? AND id > ?',
                [$dup->zoom_message_id, $dup->keepid]
            );
        }
        $duplicates->close();

        $index = new xmldb_index('zoom-message-id', XMLDB_INDEX_NOTUNIQUE, ['zoom_message_id']);
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
        $index = new xmldb_index('zoom-message-id', XMLDB_INDEX_UNIQUE, ['zoom_message_id']);
        $dbman->add_index($table, $index);

        upgrade_plugin_savepoint(true, 2026071701, 'local', 'zoomchat');
    }

    return true;
}
