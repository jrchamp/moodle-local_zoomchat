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
 * Library functions for local_zoomchat.
 *
 * @package    local_zoomchat
 * @copyright  2026 Jonathan Champ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Serve files from the local_zoomchat attachment filearea.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function local_zoomchat_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;

    require_login(null, false);

    if (!in_array($filearea, ['attachment', 'webhook_attachment'])) {
        return false;
    }

    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_zoomchat', $filearea, $itemid, $filepath, $filename);
    if (!$file) {
        send_file_not_found();
    }

    $allowed = false;

    $link = $DB->get_record('local_zoomchat_message_files', ['itemid' => $itemid]);
    if ($link) {
        $message = $DB->get_record('local_zoomchat_messages', ['id' => $link->messageid]);
        if ($message) {
            $userzoomid = \tool_zoomapi\helper::get_userid_optional();
            $allowed = (
                $userzoomid !== null &&
                ($message->from_zoom_id === $userzoomid || $message->to_zoom_id === $userzoomid)
            );
        }
    }

    if (!$allowed && $filearea === 'attachment') {
        $allowed = ((int) $file->get_userid() === (int) $USER->id);
    }

    if ($allowed) {
        send_stored_file($file, DAYSECS, 0, $forcedownload, $options);
    }

    send_file_not_found();
}
