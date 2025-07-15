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
 * Unit tests for video_manager class.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

/**
 * Test cases for video_manager class.
 *
 * @group local_cloudflarestream
 */
class video_manager_test extends advanced_testcase {

    /**
     * Set up test environment.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Test creating a video record.
     */
    public function test_create_video() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        
        $videodata = [
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_PENDING,
            'file_size' => 1000000,
            'metadata' => json_encode(['original_filename' => 'test.mp4'])
        ];

        $videoid = video_manager::create_video($videodata);
        
        $this->assertIsInt($videoid);
        $this->assertGreaterThan(0, $videoid);

        // Verify the video was created
        $video = video_manager::get_video($videoid);
        $this->assertNotNull($video);
        $this->assertEquals($USER->id, $video->user_id);
        $this->assertEquals($course->id, $video->course_id);
        $this->assertEquals(video_manager::STATUS_PENDING, $video->status);
    }

    /**
     * Test updating a video record.
     */
    public function test_update_video() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        
        $videodata = [
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_PENDING,
            'file_size' => 1000000
        ];

        $videoid = video_manager::create_video($videodata);

        // Update the video
        $updatedata = [
            'status' => video_manager::STATUS_UPLOADING,
            'cloudflare_video_id' => 'cf_video_123'
        ];

        $result = video_manager::update_video($videoid, $updatedata);
        $this->assertTrue($result);

        // Verify the update
        $video = video_manager::get_video($videoid);
        $this->assertEquals(video_manager::STATUS_UPLOADING, $video->status);
        $this->assertEquals('cf_video_123', $video->cloudflare_video_id);
    }

    /**
     * Test getting videos by status.
     */
    public function test_get_videos_by_status() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();

        // Create videos with different statuses
        $pendingvideo = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_PENDING,
            'file_size' => 1000000
        ]);

        $uploadingvideo = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 124,
            'status' => video_manager::STATUS_UPLOADING,
            'file_size' => 2000000
        ]);

        $readyvideo = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 125,
            'status' => video_manager::STATUS_READY,
            'file_size' => 3000000
        ]);

        // Test getting pending videos
        $pendingvideos = video_manager::get_videos_by_status(video_manager::STATUS_PENDING);
        $this->assertCount(1, $pendingvideos);
        $this->assertEquals($pendingvideo, $pendingvideos[0]->id);

        // Test getting uploading videos
        $uploadingvideos = video_manager::get_videos_by_status(video_manager::STATUS_UPLOADING);
        $this->assertCount(1, $uploadingvideos);
        $this->assertEquals($uploadingvideo, $uploadingvideos[0]->id);

        // Test getting ready videos
        $readyvideos = video_manager::get_videos_by_status(video_manager::STATUS_READY);
        $this->assertCount(1, $readyvideos);
        $this->assertEquals($readyvideo, $readyvideos[0]->id);
    }

    /**
     * Test getting videos by user.
     */
    public function test_get_videos_by_user() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        $user2 = $this->getDataGenerator()->create_user();

        // Create videos for different users
        $uservideo1 = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_PENDING,
            'file_size' => 1000000
        ]);

        $uservideo2 = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 124,
            'status' => video_manager::STATUS_READY,
            'file_size' => 2000000
        ]);

        $othervideo = video_manager::create_video([
            'user_id' => $user2->id,
            'course_id' => $course->id,
            'moodle_file_id' => 125,
            'status' => video_manager::STATUS_READY,
            'file_size' => 3000000
        ]);

        // Test getting videos for current user
        $uservideos = video_manager::get_videos_by_user($USER->id);
        $this->assertCount(2, $uservideos);

        $uservideoIds = array_column($uservideos, 'id');
        $this->assertContains($uservideo1, $uservideoIds);
        $this->assertContains($uservideo2, $uservideoIds);
        $this->assertNotContains($othervideo, $uservideoIds);
    }

    /**
     * Test adding videos to queue.
     */
    public function test_add_to_queue() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        
        $videoid = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_PENDING,
            'file_size' => 1000000
        ]);

        $queueid = video_manager::add_to_queue($videoid, 'upload', ['test' => 'data']);
        
        $this->assertIsInt($queueid);
        $this->assertGreaterThan(0, $queueid);

        // Verify queue item was created
        global $DB;
        $queueitem = $DB->get_record(video_manager::TABLE_QUEUE, ['id' => $queueid]);
        $this->assertNotNull($queueitem);
        $this->assertEquals($videoid, $queueitem->video_id);
        $this->assertEquals('upload', $queueitem->action);
    }

    /**
     * Test getting queue items.
     */
    public function test_get_queue_items() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        
        $videoid1 = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_PENDING,
            'file_size' => 1000000
        ]);

        $videoid2 = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 124,
            'status' => video_manager::STATUS_PENDING,
            'file_size' => 2000000
        ]);

        // Add to queue
        video_manager::add_to_queue($videoid1, 'upload');
        video_manager::add_to_queue($videoid2, 'upload');

        $queueitems = video_manager::get_queue_items(10);
        $this->assertCount(2, $queueitems);
    }

    /**
     * Test getting statistics.
     */
    public function test_get_statistics() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();

        // Create videos with different statuses
        video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_PENDING,
            'file_size' => 1000000
        ]);

        video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 124,
            'status' => video_manager::STATUS_READY,
            'file_size' => 2000000
        ]);

        video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 125,
            'status' => video_manager::STATUS_ERROR,
            'file_size' => 3000000
        ]);

        $stats = video_manager::get_statistics();

        $this->assertIsArray($stats);
        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(1, $stats['pending']);
        $this->assertEquals(1, $stats['ready']);
        $this->assertEquals(1, $stats['error']);
        $this->assertEquals(6000000, $stats['storage_used']); // Sum of file sizes
    }

    /**
     * Test deleting a video.
     */
    public function test_delete_video() {
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        
        $videoid = video_manager::create_video([
            'user_id' => $USER->id,
            'course_id' => $course->id,
            'moodle_file_id' => 123,
            'status' => video_manager::STATUS_PENDING,
            'file_size' => 1000000
        ]);

        // Verify video exists
        $video = video_manager::get_video($videoid);
        $this->assertNotNull($video);

        // Delete video
        $result = video_manager::delete_video($videoid);
        $this->assertTrue($result);

        // Verify video is deleted
        $video = video_manager::get_video($videoid);
        $this->assertNull($video);
    }

    /**
     * Test video status constants.
     */
    public function test_status_constants() {
        $this->assertEquals('pending', video_manager::STATUS_PENDING);
        $this->assertEquals('uploading', video_manager::STATUS_UPLOADING);
        $this->assertEquals('processing', video_manager::STATUS_PROCESSING);
        $this->assertEquals('ready', video_manager::STATUS_READY);
        $this->assertEquals('error', video_manager::STATUS_ERROR);
    }
}