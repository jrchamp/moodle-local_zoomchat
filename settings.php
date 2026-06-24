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
 * Settings for local_zoomchat
 *
 * @package local_zoomchat
 * @copyright 2026 Jonathan Champ
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_zoomchat', new lang_string('pluginname', 'local_zoomchat'));
    $ADMIN->add('localplugins', $settings);
}

if ($hassiteconfig && $ADMIN->fulltree) {
    $settings->add(new admin_setting_configpasswordunmask(
        'local_zoomchat/webhook_secret',
        new lang_string('webhook_secret', 'local_zoomchat'),
        new lang_string('webhook_secret_desc', 'local_zoomchat'),
        '',
    ));
}
