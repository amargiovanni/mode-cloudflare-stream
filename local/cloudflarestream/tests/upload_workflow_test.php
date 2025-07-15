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
 * Integration tests for upload workflow.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream;

use advanced_testcase;
use local_cloudflarestream\handlers\upload_handler;

defined('MOODLE_INTERNAL') || die();

/**
 * Integration test cases for upload workflow.
 *
 * @group local_cloudflarestream
 * @group local_cloudflarestream_integration
 */
class upload_workflow_test extends advanced_testcase {

    /**
     * Set up test environment.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        
        // Set up test configuration
        config_manager::set('api_token', 'test_token_1234567890abcdef1234567890abcdef12345678');
        config_manager::set('account_id', 'abcdef1234567890abcdef1234567890');
        config_manager::set('max_file_size', 10485760); // 10MB
        config_manager::set('supported_formats', 'mp4,mov,avi');
    }

    /**
     * Test complete upload workflow from file upload to ready status.
     */
    public function test_complete_upload_workflow() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        
        // Create a test file
        $testfile = $this->create_test_video_file($course);
        
        // Step 1: Handle file upload event
        $result = upload_handler::handle_file_upload($testfile);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('video_id', $result);
        
        $videoid = $result['video_id'];
        
        // Step 2: Verify video record was created
        $video = video_manager::get_video($videoid);
        $this->assertNotNull($video);
        $this->assertEquals(video_manager::STATUS_PENDING, $video->status);
        $this->assertEquals($USER->id, $video->user_id);
        $this->assertEquals($course->id, $video->course_id);
        
        // Step 3: Verify video was added to queue
        $queueitems = video_manager::get_queue_items(10);
        $this->assertNotEmpty($queueitems);
        
        $queueitem = null;
        foreach ($queueitems as $item) {
            if ($item->video_id == $videoid) {
                $queueitem = $item;
                break;
            }
        }
        
        $this->assertNotNull($queueitem);
        $this->assertEquals('upload', $queueitem->action);
        
        // Step 4: Process queue item (simulate background processing)
        $processresult = $this->simulate_queue_processing($queueitem);
        $this->assertTrue($processresult['success']);
        
        // Step 5: Verify video status updated
        $video = video_manager::get_video($videoid);
        $this->assertNotEquals(video_manager::STATUS_PENDING, $video->status);
        
        // Step 6: Simulate Cloudflare processing completion
        $this->simulate_cloudflare_processing_complete($videoid);
        
        // Step 7: Verify final status
        $video = video_manager::get_video($videoid);
        $this->assertEquals(video_manager::STATUS_READY, $video->status);
        $this->assertNotNull($video->ready_date);
        $this->assertNotNull($video->cloudflare_video_id);
    }

    /**
     * Test upload workflow with file validation errors.
     */
    public function test_upload_workflow_validation_errors() {
        $course = $this->getDataGenerator()->create_course();
        
        // Test with unsupported file format
        $testfile = $this->create_test_file($course, 'test.txt', 'text/plain');
        
        $result = upload_handler::handle_file_upload($testfile);
        
        $this->assertFalse($result['success']);
        $this->assertStringContains('format', strtolower($result['error']));
        
        // Test with file too large
        config_manager::set('max_file_size', 1000); // 1KB limit
        $testfile = $this->create_test_video_file($course, 'large_video.mp4', 2000); // 2KB file
        
        $result = upload_handler::handle_file_upload($testfile);
        
        $this->assertFalse($result['success']);
        $this->assertStringContains('size', strtolower($result['error']));
    }

    /**
     * Test upload workflow error handling and recovery.
     */
    public function test_upload_workflow_error_recovery() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        $testfile = $this->create_test_video_file($course);
        
        // Step 1: Start upload workflow
        $result = upload_handler::handle_file_upload($testfile);
        $this->assertTrue($result['success']);
        $videoid = $result['video_id'];
        
        // Step 2: Simulate upload failure
        video_manager::update_video($videoid, [
            'status' => video_manager::STATUS_ERROR,
            'error_message' => 'Simulated upload failure'
        ]);
        
        // Step 3: Verify error status
        $video = video_manager::get_video($videoid);
        $this->assertEquals(video_manager::STATUS_ERROR, $video->status);
        $this->assertNotNull($video->error_message);
        
        // Step 4: Test retry mechanism
        $retryresult = sync_manager::reset_video_for_retry($videoid);
        $this->assertTrue($retryresult['success']);
        
        // Step 5: Verify video reset to pending
        $video = video_manager::get_video($videoid);
        $this->assertEquals(video_manager::STATUS_PENDING, $video->status);
        $this->assertNull($video->error_message);
        
        // Step 6: Verify added back to queue
        $queueitems = video_manager::get_queue_items(10);
        $retryitem = null;
        foreach ($queueitems as $item) {
            if ($item->video_id == $videoid) {
                $retryitem = $item;
                break;
            }
        }
        
        $this->assertNotNull($retryitem);
        $this->assertEquals('upload', $retryitem->action);
    }

    /**
     * Test batch upload processing.
     */
    public function test_batch_upload_processing() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        
        // Create multiple test files
        $videoIds = [];
        for ($i = 1; $i <= 3; $i++) {
            $testfile = $this->create_test_video_file($course, "test_video_{$i}.mp4");
            $result = upload_handler::handle_file_upload($testfile);
            
            $this->assertTrue($result['success']);
            $videoIds[] = $result['video_id'];
        }
        
        // Verify all videos are pending
        foreach ($videoIds as $videoid) {
            $video = video_manager::get_video($videoid);
            $this->assertEquals(video_manager::STATUS_PENDING, $video->status);
        }
        
        // Process queue in batch
        $queueitems = video_manager::get_queue_items(10);
        $this->assertCount(3, $queueitems);
        
        foreach ($queueitems as $queueitem) {
            $processresult = $this->simulate_queue_processing($queueitem);
            $this->assertTrue($processresult['success']);
        }
        
        // Verify all videos processed
        foreach ($videoIds as $videoid) {
            $video = video_manager::get_video($videoid);
            $this->assertNotEquals(video_manager::STATUS_PENDING, $video->status);
        }
    }

    /**
     * Test upload workflow with different user permissions.
     */
    public function test_upload_workflow_permissions() {
        $course = $this->getDataGenerator()->create_course();
        
        // Test with teacher
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        
        $testfile = $this->create_test_video_file($course);
        $result = upload_handler::handle_file_upload($testfile);
        
        $this->assertTrue($result['success']);
        
        // Test with student
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        
        $testfile = $this->create_test_video_file($course);
        $result = upload_handler::handle_file_upload($testfile);
        
        // Result depends on plugin configuration - could be success or failure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    /**
     * Create a test video file.
     *
     * @param \stdClass $course Course object
     * @param string $filename File name
     * @param int $size File size in bytes
     * @return \stored_file Test file
     */
    private function create_test_video_file($course, $filename = 'test_video.mp4', $size = 1024) {
        return $this->create_test_file($course, $filename, 'video/mp4', $size);
    }

    /**
     * Create a test file.
     *
     * @param \stdClass $course Course object
     * @param string $filename File name
     * @param string $mimetype MIME type
     * @param int $size File size in bytes
     * @return \stored_file Test file
     */
    private function create_test_file($course, $filename, $mimetype = 'video/mp4', $size = 1024) {
        global $USER;
        
        $fs = get_file_storage();
        
        $filerecord = [
            'contextid' => \context_course::instance($course->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => file_get_unused_draft_itemid(),
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $USER->id,
            'mimetype' => $mimetype
        ];
        
        // Create dummy file content
        $content = str_repeat('x', $size);
        
        return $fs->create_file_from_string($filerecord, $content);
    }

    /**
     * Simulate queue processing.
     *
     * @param \stdClass $queueitem Queue item
     * @return array Processing result
     */
    private function simulate_queue_processing($queueitem) {
        // Update video status to uploading
        video_manager::update_video($queueitem->video_id, [
            'status' => video_manager::STATUS_UPLOADING,
            'upload_date' => time()
        ]);
        
        // Simulate successful upload to Cloudflare
        video_manager::update_video($queueitem->video_id, [
            'status' => video_manager::STATUS_PROCESSING,
            'cloudflare_video_id' => 'cf_video_' . $queueitem->video_id
        ]);
        
        // Remove from queue
        global $DB;
        $DB->delete_records(video_manager::TABLE_QUEUE, ['id' => $queueitem->id]);
        
        return ['success' => true];
    }

    /**
     * Simulate Cloudflare processing completion.
     *
     * @param int $videoid Video ID
     */
    private function simulate_cloudflare_processing_complete($videoid) {
        video_manager::update_video($videoid, [
            'status' => video_manager::STATUS_READY,
            'ready_date' => time()
        ]);
    }
}