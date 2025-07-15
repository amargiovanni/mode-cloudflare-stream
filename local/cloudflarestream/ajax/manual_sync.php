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
 * AJAX endpoint for manual synchronization operations.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_cloudflarestream\sync_manager;
use local_cloudflarestream\video_manager;

// Require admin login
require_login();
admin_externalpage_setup('local_cloudflarestream_dashboard');

// Check capabilities
require_capability('moodle/site:config', context_system::instance());

$action = required_param('action', PARAM_ALPHA);

header('Content-Type: application/json');

try {
    switch ($action) {
        case 'full_sync':
            $force = optional_param('force', false, PARAM_BOOL);
            $result = sync_manager::manual_full_sync($force);
            break;

        case 'sync_video':
            $videoid = required_param('video_id', PARAM_INT);
            $result = sync_manager::sync_video($videoid);
            break;

        case 'sync_by_status':
            $status = required_param('status', PARAM_ALPHA);
            $limit = optional_param('limit', 50, PARAM_INT);
            $result = sync_manager::sync_videos_by_status($status, $limit);
            break;

        case 'cleanup_orphans':
            $dryrun = optional_param('dry_run', true, PARAM_BOOL);
            $result = sync_manager::cleanup_orphaned_videos($dryrun);
            break;

        case 'reset_video':
            $videoid = required_param('video_id', PARAM_INT);
            $result = sync_manager::reset_video_for_retry($videoid);
            break;

        case 'get_stats':
            $result = [
                'success' => true,
                'data' => sync_manager::get_sync_statistics()
            ];
            break;

        case 'get_video_details':
            $videoid = required_param('video_id', PARAM_INT);
            $video = video_manager::get_video($videoid);
            if ($video) {
                $result = [
                    'success' => true,
                    'data' => $video
                ];
            } else {
                $result = [
                    'success' => false,
                    'error' => 'Video not found'
                ];
            }
            break;

        case 'get_videos_by_status':
            $status = required_param('status', PARAM_ALPHA);
            $limit = optional_param('limit', 20, PARAM_INT);
            $videos = video_manager::get_videos_by_status($status, $limit);
            $result = [
                'success' => true,
                'data' => $videos
            ];
            break;

        default:
            $result = [
                'success' => false,
                'error' => 'Invalid action'
            ];
            break;
    }
} catch (Exception $e) {
    $result = [
        'success' => false,
        'error' => $e->getMessage()
    ];
}

echo json_encode($result);