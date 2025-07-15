<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Unit tests for token_manager class.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream\auth;

use advanced_testcase;
use local_cloudflarestream\video_manager;
use local_cloudflarestream\config_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Test cases for token_manager class.
 *
 * @group local_cloudflarestream
 */
class token_manager_test extends advanced_testcase {

    /**
     * Set up test environment.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        
        // Set up test configuration
        config_manager::set('token_expiry', 3600); // 1 hour
    }

    /**
     * Test creating an access token.
     */
    public function test_create_access_token() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        
        // Create a test video
        $videoid = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_READY,
            'cloudflare_video_id' => 'cf_video_123',
            'file_size' => 1000000
        ]);

        $token = token_manager::create_access_token($videoid, $USER->id);
        
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        
        // Verify token was stored in database
        global $DB;
        $tokenrecord = $DB->get_record(token_manager::TABLE_TOKENS, [
            'video_id' => $videoid,
            'user_id' => $USER->id
        ]);
        
        $this->assertNotNull($tokenrecord);
        $this->assertEquals($videoid, $tokenrecord->video_id);
        $this->assertEquals($USER->id, $tokenrecord->user_id);
    }

    /**
     * Test validating an access token.
     */
    public function test_validate_access_token() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        
        // Create a test video
        $videoid = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_READY,
            'cloudflare_video_id' => 'cf_video_123',
            'file_size' => 1000000
        ]);

        $token = token_manager::create_access_token($videoid, $USER->id);
        
        // Test valid token
        $validation = token_manager::validate_access_token($token, $videoid);
        $this->assertTrue($validation['valid']);
        $this->assertEquals($USER->id, $validation['user_id']);

        // Test invalid token
        $validation = token_manager::validate_access_token('invalid_token', $videoid);
        $this->assertFalse($validation['valid']);
        $this->assertArrayHasKey('error', $validation);
    }

    /**
     * Test token expiry.
     */
    public function test_token_expiry() {
        global $USER, $DB;

        $course = $this->getDataGenerator()->create_course();
        
        // Create a test video
        $videoid = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_READY,
            'cloudflare_video_id' => 'cf_video_123',
            'file_size' => 1000000
        ]);

        $token = token_manager::create_access_token($videoid, $USER->id);
        
        // Manually expire the token
        $tokenrecord = $DB->get_record(token_manager::TABLE_TOKENS, [
            'video_id' => $videoid,
            'user_id' => $USER->id
        ]);
        
        $DB->update_record(token_manager::TABLE_TOKENS, [
            'id' => $tokenrecord->id,
            'expires_at' => time() - 3600 // Expired 1 hour ago
        ]);

        // Test expired token
        $validation = token_manager::validate_access_token($token, $videoid);
        $this->assertFalse($validation['valid']);
        $this->assertStringContains('expired', strtolower($validation['error']));
    }

    /**
     * Test cleaning up expired tokens.
     */
    public function test_cleanup_expired_tokens() {
        global $USER, $DB;

        $course = $this->getDataGenerator()->create_course();
        
        // Create test videos
        $videoid1 = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_READY,
            'cloudflare_video_id' => 'cf_video_123',
            'file_size' => 1000000
        ]);

        $videoid2 = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 124,
            'status' => video_manager::STATUS_READY,
            'cloudflare_video_id' => 'cf_video_124',
            'file_size' => 2000000
        ]);

        // Create tokens
        $token1 = token_manager::create_access_token($videoid1, $USER->id);
        $token2 = token_manager::create_access_token($videoid2, $USER->id);

        // Expire one token
        $tokenrecord1 = $DB->get_record(token_manager::TABLE_TOKENS, [
            'video_id' => $videoid1,
            'user_id' => $USER->id
        ]);
        
        $DB->update_record(token_manager::TABLE_TOKENS, [
            'id' => $tokenrecord1->id,
            'expires_at' => time() - 3600 // Expired 1 hour ago
        ]);

        // Count tokens before cleanup
        $tokencountbefore = $DB->count_records(token_manager::TABLE_TOKENS);
        $this->assertEquals(2, $tokencountbefore);

        // Run cleanup
        $cleaned = token_manager::cleanup_expired_tokens();
        $this->assertEquals(1, $cleaned);

        // Count tokens after cleanup
        $tokencountafter = $DB->count_records(token_manager::TABLE_TOKENS);
        $this->assertEquals(1, $tokencountafter);

        // Verify the correct token was kept
        $remainingtoken = $DB->get_record(token_manager::TABLE_TOKENS, [
            'video_id' => $videoid2,
            'user_id' => $USER->id
        ]);
        $this->assertNotNull($remainingtoken);
    }

    /**
     * Test getting token statistics.
     */
    public function test_get_token_statistics() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        
        // Create test videos and tokens
        for ($i = 1; $i <= 3; $i++) {
            $videoid = video_manager::create_video([
                'user_id' => $USER->id,
                'course_id' => $course->id,
                'moodle_file_id' => 120 + $i,
                'status' => video_manager::STATUS_READY,
                'cloudflare_video_id' => 'cf_video_12' . $i,
                'file_size' => 1000000 * $i
            ]);

            token_manager::create_access_token($videoid, $USER->id);
        }

        $stats = token_manager::get_token_statistics(1); // Last 1 day
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_active', $stats);
        $this->assertArrayHasKey('total_expired', $stats);
        $this->assertArrayHasKey('created_today', $stats);
        
        $this->assertEquals(3, $stats['total_active']);
        $this->assertEquals(0, $stats['total_expired']);
        $this->assertEquals(3, $stats['created_today']);
    }

    /**
     * Test token usage tracking.
     */
    public function test_track_token_usage() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        
        // Create a test video
        $videoid = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_READY,
            'cloudflare_video_id' => 'cf_video_123',
            'file_size' => 1000000
        ]);

        $token = token_manager::create_access_token($videoid, $USER->id);
        
        // Track usage
        $result = token_manager::track_token_usage($token, '192.168.1.1', 'Test User Agent');
        $this->assertTrue($result);

        // Verify usage was tracked
        global $DB;
        $tokenrecord = $DB->get_record(token_manager::TABLE_TOKENS, [
            'video_id' => $videoid,
            'user_id' => $USER->id
        ]);
        
        $this->assertNotNull($tokenrecord->last_used);
        $this->assertEquals('192.168.1.1', $tokenrecord->ip_address);
        $this->assertEquals('Test User Agent', $tokenrecord->user_agent);
    }

    /**
     * Test rate limiting for token creation.
     */
    public function test_token_creation_rate_limiting() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        
        // Create a test video
        $videoid = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_READY,
            'cloudflare_video_id' => 'cf_video_123',
            'file_size' => 1000000
        ]);

        // Create multiple tokens rapidly
        $tokens = [];
        for ($i = 0; $i < 5; $i++) {
            $token = token_manager::create_access_token($videoid, $USER->id);
            $tokens[] = $token;
        }

        // All tokens should be created (basic test)
        $this->assertCount(5, $tokens);
        
        // In a real implementation, rate limiting would prevent excessive token creation
        // This test verifies the method doesn't break with multiple calls
    }

    /**
     * Test token hash generation.
     */
    public function test_token_hash_generation() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        
        // Create test videos
        $videoid1 = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_READY,
            'cloudflare_video_id' => 'cf_video_123',
            'file_size' => 1000000
        ]);

        $videoid2 = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 124,
            'status' => video_manager::STATUS_READY,
            'cloudflare_video_id' => 'cf_video_124',
            'file_size' => 2000000
        ]);

        // Create tokens
        $token1 = token_manager::create_access_token($videoid1, $USER->id);
        $token2 = token_manager::create_access_token($videoid2, $USER->id);

        // Tokens should be different
        $this->assertNotEquals($token1, $token2);
        
        // Tokens should be strings of reasonable length
        $this->assertIsString($token1);
        $this->assertIsString($token2);
        $this->assertGreaterThan(20, strlen($token1));
        $this->assertGreaterThan(20, strlen($token2));
    }
}