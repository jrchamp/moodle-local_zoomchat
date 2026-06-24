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
 * @package    local_zoomchat
 * @copyright  2026 Jonathan Champ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

use core\clock;
use core\context\system as context_system;
use core\di;
use core\session\manager as session_manager;
use local_zoomchat\helper as zoomchat_helper;
use tool_zoomapi\helper as zoomapi_helper;

$action = required_param('action', PARAM_ALPHANUMEXT);
$userid = required_param('userid', PARAM_INT);

require_login(null, false, null, false, true);
require_sesskey();

if (isguestuser()) {
    throw new moodle_exception('noguest');
}

if ($USER->id != $userid) {
    throw new moodle_exception('invaliduser');
}

$PAGE->set_context(context_system::instance());
require_capability('local/zoomchat:chat', context_system::instance());

$zoomuserid = zoomapi_helper::get_userid_optional();
if ($zoomuserid === null) {
    echo json_encode(['error' => get_string('error:usernotfound', 'local_zoomchat')]);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    switch ($action) {
        case 'get_users':
            $builduser = function(\stdClass $user, array $extra = []): array {
                global $OUTPUT;
                return $extra + [
                    'id' => (int) $user->id,
                    'name' => fullname($user),
                    'picture' => $OUTPUT->user_picture($user, ['size' => 36, 'link' => false]),
                    'lastmessage' => '',
                    'lastmessagetime' => 0,
                    'unreadcount' => 0,
                ];
            };

            $courseid = optional_param('courseid', 0, PARAM_INT);

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

            echo json_encode(['users' => $users]);
            break;

        case 'get_messages':
            $partnerid = required_param('partnerid', PARAM_INT);
            $lasttime = optional_param('lasttime', 0, PARAM_INT);

            $partneruser = core_user::get_user($partnerid, '*', MUST_EXIST);
            $partnerzoomid = zoomapi_helper::get_zoom_userid($partneruser);

            $messages = zoomchat_helper::get_zoom_conversation_messages($USER, $partnerzoomid, 0, 50);

            $formatted = [];
            foreach ($messages as $msg) {
                if ($lasttime && $msg->timestamp <= $lasttime) {
                    continue;
                }
                $formatted[] = [
                    'id' => (int) $msg->id,
                    'message' => format_text($msg->message, FORMAT_MOODLE, ['context' => context_system::instance()]),
                    'timestamp' => (int) $msg->timestamp,
                    'mymessage' => ($msg->from_zoom_id === $zoomuserid),
                ];
            }

            echo json_encode(['messages' => $formatted]);
            break;

        case 'send_message':
            session_manager::write_close();

            $recipientid = required_param('recipientid', PARAM_INT);
            $messagetext = required_param('message', PARAM_RAW);
            $fileitemids = optional_param('file_itemids', '', PARAM_RAW);

            $messagetext = clean_text($messagetext, FORMAT_MOODLE);
            if (empty($messagetext) && empty($fileitemids)) {
                echo json_encode(['error' => 'empty_message']);
                break;
            }

            $recipient = core_user::get_user($recipientid, '*', MUST_EXIST);

            $messageid = zoomchat_helper::send_message_to_zoom(
                $USER,
                $recipient,
                $messagetext,
                $fileitemids,
                context_system::instance()->id
            );

            if ($messageid) {
                $notifydata = [
                    'messageid' => $messageid,
                    'from_userid' => (int) $USER->id,
                    'message' => format_text($messagetext, FORMAT_MOODLE, ['context' => context_system::instance()]),
                    'timestamp' => di::get(clock::class)->time(),
                    'sender_name' => fullname($USER),
                ];
                try {
                    zoomchat_helper::notify_user($recipientid, $notifydata);
                    zoomchat_helper::notify_user($USER->id, $notifydata);
                } catch (\Throwable $e) {
                    // Notification failure shouldn't prevent success response.
                }

                echo json_encode(['success' => true, 'messageid' => $messageid]);
            } else {
                echo json_encode(['success' => false]);
            }
            break;

        case 'upload_attachment':
            if (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['error' => get_string('error:fileuploadfailed', 'local_zoomchat')]);
                break;
            }

            $file = $_FILES['attachment'];

            $validmime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedmime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($detectedmime, $validmime)) {
                echo json_encode(['error' => get_string('error:onlyimages', 'local_zoomchat')]);
                break;
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
            break;

        default:
            echo json_encode(['error' => get_string('error:invalidaction', 'local_zoomchat')]);
    }
} catch (\moodle_exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
