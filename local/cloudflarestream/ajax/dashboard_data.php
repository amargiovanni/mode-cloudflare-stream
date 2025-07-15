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
 * AJAX endpoint for dashboard data updates.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

use local_cloudflarestream\video_manager;
use local_cloudflarestream\config_manager;
use local_cloudflarestream\handlers\upload_handler;
use local_cloudflarestream\auth\token_manager;

// Check permissions
require_login();
require_capability('moodle/site:config', context_system::instance());

// Verify CSRF token
if (!confirm_sesskey()) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Invalid session key']));
}

// Set JSON header
header('Content-Type: application/json');

try {
    $action = required_param('action', PARAM_ALPHA);
    
    switch ($action) {
        case 'get_statistics':
            echo json_encode(get_dashboard_statistics());
            break;
            
        case 'get_system_status':
            echo json_encode(get_system_status());
            break;
            
        case 'get_queue_status':
            echo json_encode(get_queue_status());
            break;
            
        case 'get_recent_videos':
            echo json_encode(get_recent_videos());
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Get dashboard statistics.
 *
 * @return array Statistics response
 */
function get_dashboard_statistics() {
    try {
        $videostats = video_manager::get_statistics();
        $uploadstats = upload_handler::get_upload_statistics();
        $tokenstats = token_manager::get_token_statistics(7); // Last 7 days
        
        return [
            'success' => true,
            'data' => [
                'total_videos' => $videostats['total'],
                'pending_uploads' => $videostats['pending'] + $videostats['uploading'],
                'failed_uploads' => $videostats['error'],
                'storage_used' => format_bytes($videostats['storage_used']),
                'ready_videos' => $videostats['ready'],
                'processing_videos' => $videostats['processing'],
                'queued_uploads' => $uploadstats['queued_uploads'] ?? 0,
                'active_tokens' => $tokenstats['total_active'] ?? 0
            ]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get system status.
 *
 * @return array System status response
 */
function get_system_status() {
    try {
        $status = config_manager::get_status();
        
        // Test API connection
        $client = \local_cloudflarestream\api\cloudflare_client::get_instance();
        if ($client) {
            $connectiontest = $client->test_connection();
            $status['api_connected'] = $connectiontest['success'];
            $status['api_message'] = $connectiontest['message'];
        } else {
            $status['api_connected'] = false;
            $status['api_message'] = 'Client not configured';
        }
        
        return [
            'success' => true,
            'data' => $status
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get queue status.
 *
 * @return array Queue status response
 */
function get_queue_status() {
    try {
        global $DB;
        
        $total = $DB->count_records(video_manager::TABLE_QUEUE);
        $pending = $DB->count_records_select(video_manager::TABLE_QUEUE, 'next_attempt <= ?', [time()]);
        $failed = $DB->count_records_select(video_manager::TABLE_QUEUE, 'attempts >= max_attempts');
        
        return [
            'success' => true,
            'data' => [
                'total' => $total,
                'pending' => $pending,
                'failed' => $failed
            ]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get recent videos.
 *
 * @return array Recent videos response
 */
function get_recent_videos() {
    try {
        global $DB;
        
        $sql = "SELECT v.*, c.fullname as course_name, u.firstname, u.lastname
                FROM {" . video_manager::TABLE_VIDEOS . "} v
                LEFT JOIN {course} c ON c.id = v.course_id
                LEFT JOIN {user} u ON u.id = v.user_id
                ORDER BY v.upload_date DESC";
        
        $videos = $DB->get_records_sql($sql, [], 0, 10);
        
        $videodata = [];
        foreach ($videos as $video) {
            $metadata = json_decode($video->metadata ?: '{}', true);
            $videodata[] = [
                'id' => $video->id,
                'filename' => $metadata['original_filename'] ?? 'Unknown',
                'status' => $video->status,
                'course_name' => $video->course_name ?? 'Unknown',
                'user_name' => fullname($video),
                'upload_date' => userdate($video->upload_date),
                'file_size' => format_bytes($video->file_size)
            ];
        }
        
        return [
            'success' => true,
            'data' => $videodata
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Format bytes to human readable format.
 *
 * @param int $bytes Number of bytes
 * @return string Formatted string
 */
function format_bytes($bytes) {
    if ($bytes == 0) {
        return '0 B';
    }
    
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $factor = floor(log($bytes, 1024));
    
    return sprintf('%.1f %s', $bytes / pow(1024, $factor), $units[$factor]);
}