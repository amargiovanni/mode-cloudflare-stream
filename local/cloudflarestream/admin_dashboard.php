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
 * Admin dashboard for Cloudflare Stream plugin.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_cloudflarestream\video_manager;
use local_cloudflarestream\config_manager;
use local_cloudflarestream\handlers\upload_handler;
use local_cloudflarestream\task\cleanup_tokens;
use local_cloudflarestream\auth\token_manager;

// Check permissions
admin_externalpage_setup('local_cloudflarestream_dashboard');

$PAGE->set_title(get_string('dashboard', 'local_cloudflarestream'));
$PAGE->set_heading(get_string('dashboard', 'local_cloudflarestream'));

// Add JavaScript for dashboard functionality
$PAGE->requires->js_call_amd('local_cloudflarestream/admin', 'initDashboard');

// Handle actions
$action = optional_param('action', '', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_INT);

if ($action && confirm_sesskey()) {
    switch ($action) {
        case 'sync_videos':
            if ($confirm) {
                $result = \local_cloudflarestream\sync_manager::manual_full_sync(false);
                if ($result['success']) {
                    \core\notification::success("Sync completed: {$result['updated']} videos updated, {$result['errors']} errors");
                } else {
                    \core\notification::error('Sync failed: ' . ($result['error'] ?? 'Unknown error'));
                }
                redirect($PAGE->url);
            }
            break;
            
        case 'sync_all_videos':
            if ($confirm) {
                $result = \local_cloudflarestream\sync_manager::manual_full_sync(true);
                if ($result['success']) {
                    \core\notification::success("Full sync completed: {$result['updated']} videos updated, {$result['errors']} errors");
                } else {
                    \core\notification::error('Full sync failed: ' . ($result['error'] ?? 'Unknown error'));
                }
                redirect($PAGE->url);
            }
            break;
            
        case 'cleanup_orphans':
            if ($confirm) {
                $result = \local_cloudflarestream\sync_manager::cleanup_orphaned_videos(false);
                if ($result['success']) {
                    $message = "Orphan cleanup completed: ";
                    $message .= "{$result['moodle_orphans_cleaned']} Moodle orphans cleaned, ";
                    $message .= "{$result['cloudflare_orphans_cleaned']} Cloudflare orphans cleaned";
                    \core\notification::success($message);
                } else {
                    \core\notification::error('Orphan cleanup failed: ' . ($result['error'] ?? 'Unknown error'));
                }
                redirect($PAGE->url);
            }
            break;
            
        case 'cleanup_tokens':
            if ($confirm) {
                $cleaned = token_manager::cleanup_expired_tokens();
                \core\notification::success("Cleaned up {$cleaned} expired tokens");
                redirect($PAGE->url);
            }
            break;
            
        case 'retry_failed':
            if ($confirm) {
                $result = upload_handler::retry_failed_uploads(10);
                \core\notification::success("Retried {$result['retried']} uploads, {$result['failed']} failed");
                redirect($PAGE->url);
            }
            break;
    }
}

// Get dashboard data
$stats = get_dashboard_statistics();
$recentvideos = get_recent_videos();
$systemstatus = get_system_status();
$queuestatus = get_queue_status();

echo $OUTPUT->header();

// System status alerts
if (!$systemstatus['configured']) {
    echo $OUTPUT->notification('Plugin is not configured. Please configure API credentials.', 'warning');
}

if (!$systemstatus['api_connected']) {
    echo $OUTPUT->notification('Cannot connect to Cloudflare API. Please check your credentials.', 'error');
}

// Dashboard content
echo html_writer::start_div('cloudflare-dashboard');

// Statistics cards
echo render_statistics_cards($stats);

// System status section
echo render_system_status($systemstatus);

// Queue status section
echo render_queue_status($queuestatus);

// Recent videos section
echo render_recent_videos($recentvideos);

// Admin actions section
echo render_admin_actions();

echo html_writer::end_div();

echo $OUTPUT->footer();

/**
 * Get dashboard statistics.
 *
 * @return array Statistics data
 */
function get_dashboard_statistics() {
    $videostats = video_manager::get_statistics();
    $uploadstats = upload_handler::get_upload_statistics();
    $tokenstats = token_manager::get_token_statistics(7); // Last 7 days
    
    return [
        'videos' => $videostats,
        'uploads' => $uploadstats,
        'tokens' => $tokenstats
    ];
}

/**
 * Get recent videos.
 *
 * @return array Recent video records
 */
function get_recent_videos() {
    global $DB;
    
    $sql = "SELECT v.*, c.fullname as course_name, u.firstname, u.lastname
            FROM {" . video_manager::TABLE_VIDEOS . "} v
            LEFT JOIN {course} c ON c.id = v.course_id
            LEFT JOIN {user} u ON u.id = v.user_id
            ORDER BY v.upload_date DESC";
    
    return $DB->get_records_sql($sql, [], 0, 20);
}

/**
 * Get system status.
 *
 * @return array System status data
 */
function get_system_status() {
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
    
    return $status;
}

/**
 * Get queue status.
 *
 * @return array Queue status data
 */
function get_queue_status() {
    global $DB;
    
    $total = $DB->count_records(video_manager::TABLE_QUEUE);
    $pending = $DB->count_records_select(video_manager::TABLE_QUEUE, 'next_attempt <= ?', [time()]);
    $failed = $DB->count_records_select(video_manager::TABLE_QUEUE, 'attempts >= max_attempts');
    
    return [
        'total' => $total,
        'pending' => $pending,
        'failed' => $failed
    ];
}

/**
 * Render statistics cards.
 *
 * @param array $stats Statistics data
 * @return string HTML output
 */
function render_statistics_cards($stats) {
    global $OUTPUT;
    
    $cards = [
        [
            'title' => get_string('total_videos', 'local_cloudflarestream'),
            'value' => $stats['videos']['total'],
            'icon' => 'fa-video-camera',
            'color' => 'primary'
        ],
        [
            'title' => get_string('pending_uploads', 'local_cloudflarestream'),
            'value' => $stats['videos']['pending'] + $stats['videos']['uploading'],
            'icon' => 'fa-upload',
            'color' => 'warning'
        ],
        [
            'title' => get_string('failed_uploads', 'local_cloudflarestream'),
            'value' => $stats['videos']['error'],
            'icon' => 'fa-exclamation-triangle',
            'color' => 'danger'
        ],
        [
            'title' => get_string('storage_used', 'local_cloudflarestream'),
            'value' => format_bytes($stats['videos']['storage_used']),
            'icon' => 'fa-hdd-o',
            'color' => 'info'
        ]
    ];
    
    $context = ['cards' => $cards];
    return $OUTPUT->render_from_template('local_cloudflarestream/dashboard_cards', $context);
}

/**
 * Render system status section.
 *
 * @param array $status System status data
 * @return string HTML output
 */
function render_system_status($status) {
    global $OUTPUT;
    
    $context = [
        'configured' => $status['configured'],
        'valid' => $status['valid'],
        'api_connected' => $status['api_connected'],
        'api_message' => $status['api_message'],
        'errors' => $status['errors'],
        'warnings' => $status['warnings']
    ];
    
    return $OUTPUT->render_from_template('local_cloudflarestream/system_status', $context);
}

/**
 * Render queue status section.
 *
 * @param array $queue Queue status data
 * @return string HTML output
 */
function render_queue_status($queue) {
    global $OUTPUT;
    
    $context = [
        'total' => $queue['total'],
        'pending' => $queue['pending'],
        'failed' => $queue['failed']
    ];
    
    return $OUTPUT->render_from_template('local_cloudflarestream/queue_status', $context);
}

/**
 * Render recent videos section.
 *
 * @param array $videos Recent video records
 * @return string HTML output
 */
function render_recent_videos($videos) {
    global $OUTPUT;
    
    $videodata = [];
    foreach ($videos as $video) {
        $metadata = json_decode($video->metadata ?: '{}', true);
        $videodata[] = [
            'id' => $video->id,
            'filename' => $metadata['original_filename'] ?? 'Unknown',
            'status' => $video->status,
            'status_class' => get_status_class($video->status),
            'course_name' => $video->course_name ?? 'Unknown',
            'user_name' => fullname($video),
            'upload_date' => userdate($video->upload_date),
            'file_size' => format_bytes($video->file_size),
            'error_message' => $video->error_message
        ];
    }
    
    $context = ['videos' => $videodata];
    return $OUTPUT->render_from_template('local_cloudflarestream/recent_videos', $context);
}

/**
 * Render admin actions section.
 *
 * @return string HTML output
 */
function render_admin_actions() {
    global $OUTPUT, $PAGE;
    
    $actions = [
        [
            'title' => 'Sync Videos',
            'description' => 'Sync video status with Cloudflare Stream',
            'action' => 'sync_videos',
            'class' => 'btn-primary',
            'icon' => 'fa-refresh'
        ],
        [
            'title' => 'Cleanup Tokens',
            'description' => 'Remove expired access tokens',
            'action' => 'cleanup_tokens',
            'class' => 'btn-secondary',
            'icon' => 'fa-trash'
        ],
        [
            'title' => 'Retry Failed',
            'description' => 'Retry failed video uploads',
            'action' => 'retry_failed',
            'class' => 'btn-warning',
            'icon' => 'fa-repeat'
        ]
    ];
    
    foreach ($actions as &$action) {
        $action['url'] = new moodle_url($PAGE->url, [
            'action' => $action['action'],
            'confirm' => 1,
            'sesskey' => sesskey()
        ]);
    }
    
    $context = ['actions' => $actions];
    return $OUTPUT->render_from_template('local_cloudflarestream/admin_actions', $context);
}

/**
 * Get CSS class for video status.
 *
 * @param string $status Video status
 * @return string CSS class
 */
function get_status_class($status) {
    switch ($status) {
        case video_manager::STATUS_READY:
            return 'success';
        case video_manager::STATUS_PROCESSING:
        case video_manager::STATUS_UPLOADING:
            return 'warning';
        case video_manager::STATUS_ERROR:
            return 'danger';
        default:
            return 'secondary';
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