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

namespace local_zoomchat;

use core\context\system as context_system;
use core\hook\output\before_http_headers;
use Throwable;
use tool_realtime\channel;
use tool_realtime\manager as realtime_manager;

/**
 * Hook callbacks for local_zoomchat.
 *
 * @package    local_zoomchat
 * @copyright  2026 Jonathan Champ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class callbacks {
    /**
     * Inject the floating chat bubble and modal on every page.
     *
     * @param before_http_headers $hook
     */
    public static function before_http_headers(before_http_headers $hook): void {
        global $USER, $PAGE;

        if (
            (defined('AJAX_SCRIPT') && \AJAX_SCRIPT) ||
            (defined('CLI_SCRIPT') && \CLI_SCRIPT) ||
            (defined('WS_SCRIPT') && \WS_SCRIPT)
        ) {
            return;
        }

        if (!isloggedin() || isguestuser()) {
            return;
        }

        try {
            if (!has_capability('local/zoomchat:chat', context_system::instance())) {
                return;
            }
        } catch (Throwable $e) {
            // Before the plugin is installed, the capability doesn't exist...
            return;
        }

        $channelhash = null;
        $realtimeenabled = false;
        if (class_exists(realtime_manager::class) && realtime_manager::is_enabled('local_zoomchat')) {
            $channel = new channel(
                context_system::instance(),
                'local_zoomchat',
                'zoomchat',
                0,
                json_encode(['userid' => (int) $USER->id])
            );
            $channel->subscribe();
            $channelhash = $channel->get_hash();
            $realtimeenabled = true;
        }

        $courseid = 0;
        try {
            $coursecontext = $PAGE->context->get_course_context(false);
            if ($coursecontext && $coursecontext->instanceid != SITEID) {
                $courseid = $coursecontext->instanceid;
            }
        } catch (Throwable $e) {
            // Not in a course context.
            $e->getMessage();
        }

        $PAGE->requires->js_call_amd('local_zoomchat/main', 'init', [
            [
                'userid' => (int) $USER->id,
                'courseid' => $courseid,
                'realtimeChannelHash' => $channelhash,
                'realtimeEnabled' => $realtimeenabled,
            ],
        ]);
    }
}
