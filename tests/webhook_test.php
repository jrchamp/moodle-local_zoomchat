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
 * Unit tests for the Zoom webhook signature handling.
 *
 * @package local_zoomchat
 * @copyright 2026 Jonathan Champ
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_zoomchat;

use advanced_testcase;
use local_zoomchat\output\webhook;
use ReflectionMethod;

/**
 * Unit tests for the Zoom webhook signature handling.
 *
 * @package local_zoomchat
 * @copyright 2026 Jonathan Champ
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(webhook::class)]
final class webhook_test extends advanced_testcase {
    /**
     * Remove request headers set by individual tests.
     */
    protected function tearDown(): void {
        unset($_SERVER['HTTP_X_ZM_SIGNATURE'], $_SERVER['HTTP_X_ZM_REQUEST_TIMESTAMP']);
        parent::tearDown();
    }

    /**
     * Invoke a protected static method of the webhook class.
     *
     * @param string $method The method name.
     * @param array $args The method arguments.
     * @return mixed
     */
    private function invoke_protected(string $method, array $args) {
        $reflection = new ReflectionMethod(webhook::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs(null, $args);
    }

    /**
     * Invoke the validation challenge handler and decode its response.
     *
     * @param string $plaintoken The plainToken to send.
     * @return array
     */
    private function invoke_challenge(string $plaintoken): array {
        ob_start();
        try {
            $this->invoke_protected('handle_validation_challenge', [['payload' => ['plainToken' => $plaintoken]]]);
        } finally {
            $output = ob_get_clean();
        }

        return json_decode($output, true);
    }

    /**
     * A correctly signed body is accepted.
     */
    public function test_validate_signature_accepts_valid_signature(): void {
        $this->resetAfterTest();
        set_config('webhook_secret', 'test-webhook-secret', 'local_zoomchat');

        $clock = $this->mock_clock_with_frozen();
        $rawbody = '{"event":"chat_message.sent","payload":{}}';
        $timestamp = $clock->time();
        $_SERVER['HTTP_X_ZM_SIGNATURE'] = 'v0=' . hash_hmac('sha256', "v0:$timestamp:$rawbody", 'test-webhook-secret');
        $_SERVER['HTTP_X_ZM_REQUEST_TIMESTAMP'] = (string) $timestamp;

        $this->assertTrue($this->invoke_protected('validate_signature', [$rawbody]));
    }

    /**
     * A body that does not match the signature is rejected.
     */
    public function test_validate_signature_rejects_tampered_body(): void {
        $this->resetAfterTest();
        set_config('webhook_secret', 'test-webhook-secret', 'local_zoomchat');

        $clock = $this->mock_clock_with_frozen();
        $rawbody = '{"event":"chat_message.sent","payload":{}}';
        $timestamp = $clock->time();
        $_SERVER['HTTP_X_ZM_SIGNATURE'] = 'v0=' . hash_hmac('sha256', "v0:$timestamp:$rawbody", 'test-webhook-secret');
        $_SERVER['HTTP_X_ZM_REQUEST_TIMESTAMP'] = (string) $timestamp;

        $this->assertFalse($this->invoke_protected('validate_signature', [$rawbody . ' ']));
    }

    /**
     * A signature outside the timestamp window is rejected.
     */
    public function test_validate_signature_rejects_stale_timestamp(): void {
        $this->resetAfterTest();
        set_config('webhook_secret', 'test-webhook-secret', 'local_zoomchat');

        $clock = $this->mock_clock_with_frozen();
        $rawbody = '{"event":"chat_message.sent","payload":{}}';
        $timestamp = $clock->time() - 400;
        $_SERVER['HTTP_X_ZM_SIGNATURE'] = 'v0=' . hash_hmac('sha256', "v0:$timestamp:$rawbody", 'test-webhook-secret');
        $_SERVER['HTTP_X_ZM_REQUEST_TIMESTAMP'] = (string) $timestamp;

        $this->assertFalse($this->invoke_protected('validate_signature', [$rawbody]));
    }

    /**
     * The validation challenge refuses event-signature preimages.
     */
    public function test_validation_challenge_rejects_event_signature_preimage(): void {
        $this->resetAfterTest();
        set_config('webhook_secret', 'test-webhook-secret', 'local_zoomchat');

        $response = $this->invoke_challenge('v0:1700000000:{"event":"chat_message.sent"}');

        $this->assertSame('Invalid plainToken', $response['error']);
    }

    /**
     * Document and image MIME types are accepted; active types are not.
     */
    public function test_is_allowed_file_mimetype(): void {
        $this->assertTrue($this->invoke_protected('is_allowed_file_mimetype', ['image/png']));
        $this->assertTrue($this->invoke_protected('is_allowed_file_mimetype', ['application/pdf']));
        $this->assertTrue($this->invoke_protected('is_allowed_file_mimetype', ['text/plain']));
        $this->assertTrue($this->invoke_protected('is_allowed_file_mimetype', ['application/vnd.oasis.opendocument.text']));
        $this->assertFalse($this->invoke_protected('is_allowed_file_mimetype', ['text/html']));
        $this->assertFalse($this->invoke_protected('is_allowed_file_mimetype', ['image/svg+xml']));
    }

    /**
     * The validation challenge returns the HMAC expected by Zoom.
     */
    public function test_validation_challenge_returns_encrypted_token(): void {
        $this->resetAfterTest();
        set_config('webhook_secret', 'test-webhook-secret', 'local_zoomchat');

        $plaintoken = 'qgg8vlvZRS6UYooatFL8Aw';
        $response = $this->invoke_challenge($plaintoken);

        $this->assertSame($plaintoken, $response['plainToken']);
        $this->assertSame(
            hash_hmac('sha256', $plaintoken, 'test-webhook-secret'),
            $response['encryptedToken']
        );
    }
}
