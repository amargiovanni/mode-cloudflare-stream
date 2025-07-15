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
 * AJAX endpoint for getting video upload status.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

use local_cloudflarestream\video_manager;
use local_cloudflarestream\api\stream_manager;

// Check authentication
require_login();

// Verify CSRF token
if (!confirm_sesskey()) {
    http_response_code(403);
    die(json_encode(['error' => 'Invalid session key']));
}

// Set JSON header
header('Content-Type: application/json');

try {
    $action = required_param('action', PARAM_ALPHA);
    
    switch ($action) {
        case 'get_status':
            $videoid = required_param('video_id', PARAM_INT);
            echo json_encode(get_video_status($videoid));
            break;
            
        case 'get_user_videos':
            $courseid = optional_param('course_id', 0, PARAM_INT);
            echo json_encode(get_user_videos($courseid));
            break;
            
        case 'retry_upload':
            $videoid = required_param('video_id', PARAM_INT);
            echo json_encode(retry_video_upload($videoid));
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Get status for a specific video.
 *
 * @param int $videoid Video record ID
 * @return array Status information
 */
function get_video_status($videoid) {
    global $USER;
    
    $video = video_manager::get_video($videoid);
    if (!$video) {
        return ['error' => 'Video not found'];
    }
    
    // Check permissions
    if ($video->user_id != $USER->id && !has_capability('moodle/site:config', context_system::instance())) {
        return ['error' => 'Access denied'];
    }
    
    $streammanager = stream_manager::get_instance();
    $progress = $streammanager ? $streammanager->get_upload_progress($videoid) : ['status' => $video->status, 'progress' => 0];
    
    $metadata = json_decode($video->metadata ?: '{}', true);
    
    return [
        'id' => $video->id,
        'status' => $video->status,
        'progress' => $progress['progress'],
        'filename' => $metadata['original_filename'] ?? 'Unknown',
        'filesize' => $video->file_size,
        'upload_date' => $video->upload_date,
        'ready_date' => $video->ready_date,
        'error_message' => $video->error_message,
        'cloudflare_video_id' => $video->cloudflare_video_id,
        'thumbnail_url' => $video->thumbnail_url,
        'duration' => $video->duration
    ];
}

/**
 * Get videos for current user.
 *
 * @param int $courseid Optional course filter
 * @return array User videos
 */
function get_user_videos($courseid = 0) {
    global $USER;
    
    $conditions = ['user_id' => $USER->id];
    if ($courseid > 0) {
        $conditions['course_id'] = $courseid;
    }
    
    $videos = video_manager::get_videos_by_user($USER->id);
    $result = [];
    
    foreach ($videos as $video) {
        if ($courseid > 0 && $video->course_id != $courseid) {
            continue;
        }
        
        $metadata = json_decode($video->metadata ?: '{}', true);
        
        $result[] = [
            'id' => $video->id,
            'status' => $video->status,
            'filename' => $metadata['original_filename'] ?? 'Unknown',
            'filesize' => $video->file_size,
            'upload_date' => $video->upload_date,
            'ready_date' => $video->ready_date,
            'course_id' => $video->course_id,
            'error_message' => $video->error_message,
            'progress' => get_progress_for_status($video->status)
        ];
    }
    
    return ['videos' => $result];
}

/**
 * Retry failed video upload.
 *
 * @param int $videoid Video record ID
 * @return array Retry result
 */
function retry_video_upload($videoid) {
    global $USER;
    
    $video = video_manager::get_video($videoid);
    if (!$video) {
        return ['error' => 'Video not found'];
    }
    
    // Check permissions
    if ($video->user_id != $USER->id && !has_capability('moodle/site:config', context_system::instance())) {
        return ['error' => 'Access denied'];
    }
    
    if ($video->status !== video_manager::STATUS_ERROR) {
        return ['error' => 'Video is not in error state'];
    }
    
    try {
        // Reset video status
        video_manager::update_video($videoid, [
            'status' => video_manager::STATUS_PENDING,
            'error_message' => null
        ]);
        
        // Re-queue for upload
        $metadata = json_decode($video->metadata ?: '{}', true);
        video_manager::queue_action($videoid, 'upload', 5, [
            'metadata' => $metadata,
            'retry' => true
        ]);
        
        return [
            'success' => true,
            'message' => 'Video queued for retry'
        ];
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Get progress percentage for status.
 *
 * @param string $status Video status
 * @return int Progress percentage
 */
function get_progress_for_status($status) {
    switch ($status) {
        case video_manager::STATUS_PENDING:
            return 0;
        case video_manager::STATUS_UPLOADING:
            return 25;
        case video_manager::STATUS_PROCESSING:
            return 50;
        case video_manager::STATUS_READY:
            return 100;
        case video_manager::STATUS_ERROR:
            return 0;
        default:
            return 0;
    }
}