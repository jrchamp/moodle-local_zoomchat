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
 * External functions and service definitions for local_zoomchat.
 *
 * @package local_zoomchat
 * @copyright 2026 Jonathan Champ
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_zoomchat\external\get_messages;
use local_zoomchat\external\get_users;
use local_zoomchat\external\send_message;

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_zoomchat_get_messages' => [
        'classname' => get_messages::class,
        'description' => 'Get messages for a conversation with another user',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_zoomchat_get_users' => [
        'classname' => get_users::class,
        'description' => 'Get users the current user can chat with (conversations + course contacts)',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_zoomchat_send_message' => [
        'classname' => send_message::class,
        'description' => 'Send a message via Zoom Team Chat',
        'type' => 'write',
        'ajax' => true,
    ],
];
