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
 * AJAX endpoints for local_zoomchat.
 *
 * @package local_zoomchat
 * @copyright 2026 Jonathan Champ
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

use core\context\system as context_system;
use core\exception\moodle_exception;
use Throwable;

require_login(null, false, null, false, true);
require_sesskey();

if (isguestuser()) {
    throw new moodle_exception('noguest');
}

$PAGE->set_context(context_system::instance());
require_capability('local/zoomchat:chat', context_system::instance());

header('Content-Type: application/json; charset=utf-8');

$action = required_param('action', PARAM_ALPHANUMEXT);

try {
    if ($action !== 'upload_attachment') {
        throw new moodle_exception('error:invalidaction', 'local_zoomchat');
    }

    if (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => get_string('error:fileuploadfailed', 'local_zoomchat')]);
        exit;
    }

    $file = $_FILES['attachment'];

    $validmime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedmime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($detectedmime, $validmime)) {
        echo json_encode(['error' => get_string('error:onlyimages', 'local_zoomchat')]);
        exit;
    }

    $fs = get_file_storage();
    $filename = clean_filename($file['name']);
    $itemid = random_int(0, 2147483647);

    $filerecord = [
        'contextid' => context_system::instance()->id,
        'component' => 'local_zoomchat',
        'filearea' => 'attachment',
        'itemid' => $itemid,
        'filepath' => '/',
        'filename' => $filename,
        'userid' => $USER->id,
    ];

    $storedfile = $fs->create_file_from_pathname($filerecord, $file['tmp_name']);
    $url = moodle_url::make_pluginfile_url(
        $storedfile->get_contextid(),
        'local_zoomchat',
        'attachment',
        $storedfile->get_itemid(),
        '/',
        $storedfile->get_filename()
    );

    echo json_encode([
        'html' => '<img src="' . $url->out() . '" alt="' . s($filename) . '">',
        'fileitemid' => $itemid,
    ]);
} catch (moodle_exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
