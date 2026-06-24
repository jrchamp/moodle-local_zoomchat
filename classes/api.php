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
 * Handles API calls to Zoom REST API for local_zoomchat.
 *
 * @package local_zoomchat
 * @copyright 2026 Jonathan Champ
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomchat;

use core\exception\moodle_exception;
use Throwable;
use tool_zoomapi\api as zoomapi_base;

/**
 * API class extending tool_zoomapi for chat-specific endpoints.
 */
class api extends zoomapi_base {
    /**
     * Send a chat message via the Zoom API.
     *
     * @param string $userid Zoom user ID of the sender.
     * @param array $data Message payload.
     * @return array
     */
    public function create_user_message($userid, $data = []) {
        return $this->make_call('post', "chat/users/{$userid}/messages", $data);
    }

    /**
     * Get chat file info from Zoom.
     *
     * @param string $fileid The Zoom file ID.
     * @param array $data Optional query parameters.
     * @return array
     */
    public function get_chat_file($fileid, $data = []) {
        return $this->make_call('get', "chat/files/{$fileid}", $data);
    }

    /**
     * Download a chat file from Zoom using the file ID.
     *
     * @param string $fileid The Zoom file ID.
     * @return array Array with 'content', 'filename', 'filesize'.
     * @throws \moodle_exception
     */
    public function download_chat_file(string $fileid): array {
        $fileinfo = $this->get_chat_file($fileid);
        if (empty($fileinfo['download_url'])) {
            throw new moodle_exception('error:api', 'tool_zoomapi', '', 'No download URL for file');
        }

        $accesstoken = $this->get_access_token();

        try {
            $response = $this->client->get($fileinfo['download_url'], [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accesstoken,
                ],
                'http_errors' => false,
                'allow_redirects' => true,
                'connect_timeout' => 30,
                'timeout' => 120,
            ]);

            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new moodle_exception('error:api', 'tool_zoomapi', '', 'File download failed with status ' . $status);
            }

            return [
                'content' => $response->getBody()->getContents(),
                'filename' => $fileinfo['file_name'] ?? 'unknown',
                'filesize' => $fileinfo['file_size'] ?? 0,
            ];
        } catch (moodle_exception $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new moodle_exception('error:api', 'tool_zoomapi', '', $e->getMessage());
        }
    }

    /**
     * Upload a file for use in Team Chat messages.
     *
     * @param string $userid The Zoom user ID.
     * @param string $filepath Local filesystem path to the file.
     * @param string $filename Original filename.
     * @return string The Zoom file ID.
     * @throws \moodle_exception
     */
    public function upload_chat_file(string $userid, string $filepath, string $filename): string {
        $accesstoken = $this->get_access_token();
        $url = "https://file.zoom.us/v2/chat/users/{$userid}/files";

        try {
            $response = $this->client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accesstoken,
                ],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($filepath, 'r'),
                        'filename' => $filename,
                    ],
                ],
                'http_errors' => false,
                'allow_redirects' => true,
                'connect_timeout' => 30,
                'timeout' => 120,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            $status = $response->getStatusCode();

            if ($status >= 400) {
                $message = $body['message'] ?? $body['reason'] ?? 'File upload failed';
                throw new moodle_exception('error:api', 'tool_zoomapi', '', $message);
            }

            return $body['id'] ?? '';
        } catch (moodle_exception $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new moodle_exception('error:api', 'tool_zoomapi', '', $e->getMessage());
        }
    }
}
