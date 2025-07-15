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
 * Integration tests for player functionality.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream;

use advanced_testcase;
use local_cloudflarestream\handlers\player_handler;
use local_cloudflarestream\auth\token_manager;
use local_cloudflarestream\access_controller;

defined('MOODLE_INTERNAL') || die();

/**
 * Integration test cases for player functionality.
 *
 * @group local_cloudflarestream
 * @group local_cloudflarestream_integration
 */
class player_integration_test extends advanced_testcase {

    /**
     * Set up test environment.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        
        // Set up test configuration
        config_manager::set('api_token', 'test_token_1234567890abcdef1234567890abcdef12345678');
        config_manager::set('account_id', 'abcdef1234567890abcdef1234567890');
        config_manager::set('token_expiry', 3600);
        config_manager::set('player_controls', 1);
        config_manager::set('autoplay', 0);
    }

    /**
     * Test complete player workflow with authentication.
     */
    public function test_complete_player_workflow() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        
        // Create a ready video
        $videoid = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_READY,
            'cloudflare_video_id' => 'cf_video_123',
            'file_size' => 1000000,
            'ready_date' => time()
        ]);

        // Step 1: Check access permissions
        $accessresult = access_controller::check_video_access($videoid, $USER->id);
        $this->assertTrue($accessresult['allowed']);

        // Step 2: Generate access token
        $token = token_manager::create_access_token($videoid, $USER->id);
        $this->assertNotEmpty($token);

        // Step 3: Validate token
        $validation = token_manager::validate_access_token($token, $videoid);
        $this->assertTrue($validation['valid']);
        $this->assertEquals($USER->id, $validation['user_id']);

        // Step 4: Generate player HTML
        $playerhtml = player_handler::generate_player_html($videoid, $token);
        $this->assertNotEmpty($playerhtml);
        $this->assertStringContains('cf_video_123', $playerhtml);
        $this->assertStringContains('stream-player', $playerhtml);

        // Step 5: Track token usage
        $usageresult = token_manager::track_token_usage($token, '192.168.1.1', 'Test Browser');
        $this->assertTrue($usageresult);

        // Step 6: Verify token usage was recorded
        global $DB;
        $tokenrecord = $DB->get_record(token_manager::TABLE_TOKENS, [
            'video_id' => $videoid,
            'user_id' => $USER->id
        ]);
        
        $this->assertNotNull($tokenrecord->last_used);
        $this->assertEquals('192.168.1.1', $tokenrecord->ip_address);
    }

    /**
     * Test player access control with different user roles.
     */
    public function test_player_access_control() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        
        // Create a video owned by admin
        $videoid = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_READY,
            'cloudflare_video_id' => 'cf_video_123',
            'file_size' => 1000000,
            'ready_date' => time()
        ]);

        // Test access as owner (admin)
        $accessresult = access_controller::check_video_access($videoid, $USER->id);
        $this->assertTrue($accessresult['allowed']);

        // Test access as enrolled teacher
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        
        $accessresult = access_controller::check_video_access($videoid, $teacher->id);
        $this->assertTrue($accessresult['allowed']);

        // Test access as enrolled student
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        
        $accessresult = access_controller::check_video_access($videoid, $student->id);
        $this->assertTrue($accessresult['allowed']); // Assuming students can view

        // Test access as non-enrolled user
        $outsider = $this->getDataGenerator()->create_user();
        
        $accessresult = access_controller::check_video_access($videoid, $outsider->id);
        $this->assertFalse($accessresult['allowed']);
        $this->assertArrayHasKey('reason', $accessresult);
    }

    /**
     * Test player with expired tokens.
     */
    public function test_player_expired_token() {
        global $USER, $DB;

        $course = $this->getDataGenerator()->create_course();
        
        // Create a ready video
        $videoid = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_READY,
            'cloudflare_video_id' => 'cf_video_123',
            'file_size' => 1000000,
            'ready_date' => time()
        ]);

        // Generate token
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

        // Test validation with expired token
        $validation = token_manager::validate_access_token($token, $videoid);
        $this->assertFalse($validation['valid']);
        $this->assertStringContains('expired', strtolower($validation['error']));

        // Test player generation with expired token
        $playerhtml = player_handler::generate_player_html($videoid, $token);
        $this->assertStringContains('error', strtolower($playerhtml));
        $this->assertStringContains('expired', strtolower($playerhtml));
    }

    /**
     * Test player fallback mechanism.
     */
    public function test_player_fallback_mechanism() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        
        // Create a video that's not ready
        $videoid = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_PROCESSING,
            'cloudflare_video_id' => 'cf_video_123',
            'file_size' => 1000000
        ]);

        // Try to generate player for non-ready video
        $token = token_manager::create_access_token($videoid, $USER->id);
        $playerhtml = player_handler::generate_player_html($videoid, $token);
        
        // Should show processing status instead of player
        $this->assertStringContains('processing', strtolower($playerhtml));
        $this->assertStringNotContains('stream-player', $playerhtml);

        // Test with error status video
        video_manager::update_video($videoid, [
            'status' => video_manager::STATUS_ERROR,
            'error_message' => 'Upload failed'
        ]);

        $playerhtml = player_handler::generate_player_html($videoid, $token);
        $this->assertStringContains('error', strtolower($playerhtml));
        $this->assertStringContains('failed', strtolower($playerhtml));
    }

    /**
     * Test player configuration options.
     */
    public function test_player_configuration() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        
        // Create a ready video
        $videoid = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_READY,
            'cloudflare_video_id' => 'cf_video_123',
            'file_size' => 1000000,
            'ready_date' => time()
        ]);

        $token = token_manager::create_access_token($videoid, $USER->id);

        // Test with controls enabled
        config_manager::set('player_controls', 1);
        $playerhtml = player_handler::generate_player_html($videoid, $token);
        $this->assertStringContains('controls', $playerhtml);

        // Test with controls disabled
        config_manager::set('player_controls', 0);
        $playerhtml = player_handler::generate_player_html($videoid, $token);
        $this->assertStringNotContains('controls', $playerhtml);

        // Test with autoplay enabled
        config_manager::set('autoplay', 1);
        $playerhtml = player_handler::generate_player_html($videoid, $token);
        $this->assertStringContains('autoplay', $playerhtml);

        // Test with autoplay disabled
        config_manager::set('autoplay', 0);
        $playerhtml = player_handler::generate_player_html($videoid, $token);
        $this->assertStringNotContains('autoplay', $playerhtml);
    }

    /**
     * Test player security features.
     */
    public function test_player_security() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        
        // Create a ready video
        $videoid = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_READY,
            'cloudflare_video_id' => 'cf_video_123',
            'file_size' => 1000000,
            'ready_date' => time()
        ]);

        // Test with invalid token
        $playerhtml = player_handler::generate_player_html($videoid, 'invalid_token');
        $this->assertStringContains('error', strtolower($playerhtml));
        $this->assertStringNotContains('cf_video_123', $playerhtml);

        // Test with token for different video
        $othervideoid = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 124,
            'status' => video_manager::STATUS_READY,
            'cloudflare_video_id' => 'cf_video_124',
            'file_size' => 2000000,
            'ready_date' => time()
        ]);

        $othertoken = token_manager::create_access_token($othervideoid, $USER->id);
        
        // Try to use token for different video
        $playerhtml = player_handler::generate_player_html($videoid, $othertoken);
        $this->assertStringContains('error', strtolower($playerhtml));
        $this->assertStringNotContains('cf_video_123', $playerhtml);
    }

    /**
     * Test player responsive design.
     */
    public function test_player_responsive_design() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        
        // Create a ready video
        $videoid = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_READY,
            'cloudflare_video_id' => 'cf_video_123',
            'file_size' => 1000000,
            'ready_date' => time()
        ]);

        $token = token_manager::create_access_token($videoid, $USER->id);
        $playerhtml = player_handler::generate_player_html($videoid, $token);

        // Check for responsive design elements
        $this->assertStringContains('responsive', strtolower($playerhtml));
        $this->assertStringContains('width', strtolower($playerhtml));
        $this->assertStringContains('height', strtolower($playerhtml));
    }

    /**
     * Test multiple concurrent player instances.
     */
    public function test_multiple_player_instances() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        
        // Create multiple ready videos
        $videoIds = [];
        for ($i = 1; $i <= 3; $i++) {
            $videoid = video_manager::create_video([
                'user_id' => $USER->id,
                'course_id' => $course->id,
                'moodle_file_id' => 120 + $i,
                'status' => video_manager::STATUS_READY,
                'cloudflare_video_id' => 'cf_video_12' . $i,
                'file_size' => 1000000 * $i,
                'ready_date' => time()
            ]);
            $videoIds[] = $videoid;
        }

        // Generate players for all videos
        $playerHtmls = [];
        foreach ($videoIds as $videoid) {
            $token = token_manager::create_access_token($videoid, $USER->id);
            $playerhtml = player_handler::generate_player_html($videoid, $token);
            
            $this->assertNotEmpty($playerhtml);
            $this->assertStringContains('stream-player', $playerhtml);
            
            $playerHtmls[] = $playerhtml;
        }

        // Verify each player is unique
        $this->assertCount(3, $playerHtmls);
        $this->assertNotEquals($playerHtmls[0], $playerHtmls[1]);
        $this->assertNotEquals($playerHtmls[1], $playerHtmls[2]);
    }
}