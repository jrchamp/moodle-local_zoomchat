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
 * Zoom Team Chat webhook endpoint for local_zoomchat.
 *
 * This script receives webhook events from Zoom Team Chat and processes them.
 * It must be publicly accessible for Zoom to deliver events.
 *
 * @package    local_zoomchat
 * @copyright  2026 Jonathan Champ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_zoomchat\output\webhook;

define('AJAX_SCRIPT', true);
define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../config.php');

// Set the correct content type.
header('Content-Type: application/json');

// Clean any rogue output buffers.
while (ob_get_level()) {
    ob_end_clean();
}

// Handle the webhook request and exit immediately.
webhook::handle_request();
die();
