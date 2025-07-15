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
 * Access control system for video streaming.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream;

use local_cloudflarestream\auth\token_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages access control for video streaming.
 */
class access_controller {

    /** @var array Cache for permission checks */
    private static $permissioncache = [];

    /**
     * Check if user can view video.
     *
     * @param int $videoid Video record ID
     * @param int $userid User ID (optional, defaults to current user)
     * @return array Access check result
     */
    public static function can_view_video($videoid, $userid = null) {
        global $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        // Check authentication
        if (!$userid || $userid == 0) {
            return [
                'allowed' => false,
                'reason' => 'user_not_authenticated',
                'message' => 'User must be logged in to view videos'
            ];
        }

        // Get video record
        $video = video_manager::get_video($videoid);
        if (!$video) {
            return [
                'allowed' => false,
                'reason' => 'video_not_found',
                'message' => get_string('error_video_not_found', 'local_cloudflarestream')
            ];
        }

        // Check video status
        if ($video->status !== video_manager::STATUS_READY) {
            return [
                'allowed' => false,
                'reason' => 'video_not_ready',
                'message' => 'Video is not ready for streaming'
            ];
        }

        // Check cache first
        $cachekey = "view_{$videoid}_{$userid}";
        if (isset(self::$permissioncache[$cachekey])) {
            return self::$permissioncache[$cachekey];
        }

        // Perform access checks
        $result = self::perform_access_checks($video, $userid, 'view');
        
        // Cache result for 5 minutes
        self::$permissioncache[$cachekey] = $result;
        
        return $result;
    }

    /**
     * Check if user can download video.
     *
     * @param int $videoid Video record ID
     * @param int $userid User ID (optional, defaults to current user)
     * @return array Access check result
     */
    public static function can_download_video($videoid, $userid = null) {
        global $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        // First check if user can view
        $viewcheck = self::can_view_video($videoid, $userid);
        if (!$viewcheck['allowed']) {
            return $viewcheck;
        }

        $video = video_manager::get_video($videoid);
        return self::perform_access_checks($video, $userid, 'download');
    }

    /**
     * Check if user can manage video.
     *
     * @param int $videoid Video record ID
     * @param int $userid User ID (optional, defaults to current user)
     * @return array Access check result
     */
    public static function can_manage_video($videoid, $userid = null) {
        global $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        $video = video_manager::get_video($videoid);
        if (!$video) {
            return [
                'allowed' => false,
                'reason' => 'video_not_found',
                'message' => get_string('error_video_not_found', 'local_cloudflarestream')
            ];
        }

        return self::perform_access_checks($video, $userid, 'manage');
    }

    /**
     * Generate secure video access token.
     *
     * @param int $videoid Video record ID
     * @param int $userid User ID (optional, defaults to current user)
     * @param array $options Token options
     * @return array Token generation result
     */
    public static function generate_access_token($videoid, $userid = null, $options = []) {
        global $USER;

        if ($userid === null) {
            $userid = $USER->id;
        }

        // Check if user can view video
        $accesscheck = self::can_view_video($videoid, $userid);
        if (!$accesscheck['allowed']) {
            return [
                'success' => false,
                'error' => $accesscheck['message']
            ];
        }

        // Generate token
        return token_manager::generate_video_token($userid, $videoid, $options);
    }

    /**
     * Validate video access with token.
     *
     * @param string $token Access token
     * @param int $videoid Video record ID
     * @return array Validation result
     */
    public static function validate_video_access($token, $videoid) {
        // Validate token
        $tokenvalidation = token_manager::validate_token($token);
        if (!$tokenvalidation['valid']) {
            return [
                'valid' => false,
                'error' => $tokenvalidation['error']
            ];
        }

        $payload = $tokenvalidation['payload'];

        // Check if token is for the requested video
        if ($payload['video_id'] != $videoid) {
            return [
                'valid' => false,
                'error' => 'Token is not valid for this video'
            ];
        }

        // Additional real-time access check
        $accesscheck = self::can_view_video($videoid, $payload['sub']);
        if (!$accesscheck['allowed']) {
            return [
                'valid' => false,
                'error' => $accesscheck['message']
            ];
        }

        return [
            'valid' => true,
            'user_id' => $payload['sub'],
            'permissions' => $payload['permissions'] ?? ['view' => true]
        ];
    }

    /**
     * Perform comprehensive access checks.
     *
     * @param \stdClass $video Video record
     * @param int $userid User ID
     * @param string $action Action to check (view, download, manage)
     * @return array Access check result
     */
    private static function perform_access_checks($video, $userid, $action) {
        // Check if user is video owner
        if ($video->user_id == $userid) {
            return [
                'allowed' => true,
                'reason' => 'owner',
                'message' => 'User is video owner'
            ];
        }

        // Check system admin capabilities
        if (has_capability('moodle/site:config', \context_system::instance(), $userid)) {
            return [
                'allowed' => true,
                'reason' => 'admin',
                'message' => 'User has admin privileges'
            ];
        }

        // Get course context
        try {
            $coursecontext = \context_course::instance($video->course_id);
        } catch (\Exception $e) {
            return [
                'allowed' => false,
                'reason' => 'invalid_course',
                'message' => 'Course context not found'
            ];
        }

        // Check course enrollment
        if (!is_enrolled($coursecontext, $userid)) {
            return [
                'allowed' => false,
                'reason' => 'not_enrolled',
                'message' => 'User is not enrolled in the course'
            ];
        }

        // Check action-specific permissions
        switch ($action) {
            case 'view':
                return self::check_view_permission($coursecontext, $userid, $video);
            
            case 'download':
                return self::check_download_permission($coursecontext, $userid, $video);
            
            case 'manage':
                return self::check_manage_permission($coursecontext, $userid, $video);
            
            default:
                return [
                    'allowed' => false,
                    'reason' => 'unknown_action',
                    'message' => 'Unknown action requested'
                ];
        }
    }

    /**
     * Check view permission.
     *
     * @param \context_course $context Course context
     * @param int $userid User ID
     * @param \stdClass $video Video record
     * @return array Permission check result
     */
    private static function check_view_permission($context, $userid, $video) {
        // Basic enrolled users can view
        if (is_enrolled($context, $userid)) {
            // Check if course is visible and accessible
            $course = get_course($video->course_id);
            if (!$course->visible && !has_capability('moodle/course:viewhiddencourses', $context, $userid)) {
                return [
                    'allowed' => false,
                    'reason' => 'course_hidden',
                    'message' => 'Course is not visible to user'
                ];
            }

            return [
                'allowed' => true,
                'reason' => 'enrolled',
                'message' => 'User is enrolled in course'
            ];
        }

        return [
            'allowed' => false,
            'reason' => 'not_enrolled',
            'message' => 'User is not enrolled in course'
        ];
    }

    /**
     * Check download permission.
     *
     * @param \context_course $context Course context
     * @param int $userid User ID
     * @param \stdClass $video Video record
     * @return array Permission check result
     */
    private static function check_download_permission($context, $userid, $video) {
        // Check if user has file management capabilities
        if (has_capability('moodle/course:managefiles', $context, $userid)) {
            return [
                'allowed' => true,
                'reason' => 'manage_files',
                'message' => 'User can manage course files'
            ];
        }

        // Check if user is teacher
        if (has_capability('moodle/course:update', $context, $userid)) {
            return [
                'allowed' => true,
                'reason' => 'teacher',
                'message' => 'User is course teacher'
            ];
        }

        return [
            'allowed' => false,
            'reason' => 'insufficient_permissions',
            'message' => 'User does not have download permissions'
        ];
    }

    /**
     * Check manage permission.
     *
     * @param \context_course $context Course context
     * @param int $userid User ID
     * @param \stdClass $video Video record
     * @return array Permission check result
     */
    private static function check_manage_permission($context, $userid, $video) {
        // Only video owner or course managers can manage
        if (has_capability('moodle/course:update', $context, $userid)) {
            return [
                'allowed' => true,
                'reason' => 'course_manager',
                'message' => 'User can manage course content'
            ];
        }

        return [
            'allowed' => false,
            'reason' => 'insufficient_permissions',
            'message' => 'User cannot manage videos'
        ];
    }

    /**
     * Get user's video access summary.
     *
     * @param int $userid User ID
     * @param int $courseid Optional course filter
     * @return array Access summary
     */
    public static function get_user_access_summary($userid, $courseid = null) {
        $summary = [
            'total_videos' => 0,
            'viewable_videos' => 0,
            'downloadable_videos' => 0,
            'manageable_videos' => 0,
            'owned_videos' => 0
        ];

        // Get videos for user or course
        if ($courseid) {
            $videos = video_manager::get_videos_by_course($courseid);
        } else {
            // Get all videos user might have access to
            $videos = self::get_accessible_videos($userid);
        }

        foreach ($videos as $video) {
            $summary['total_videos']++;

            if ($video->user_id == $userid) {
                $summary['owned_videos']++;
            }

            $viewcheck = self::can_view_video($video->id, $userid);
            if ($viewcheck['allowed']) {
                $summary['viewable_videos']++;
            }

            $downloadcheck = self::can_download_video($video->id, $userid);
            if ($downloadcheck['allowed']) {
                $summary['downloadable_videos']++;
            }

            $managecheck = self::can_manage_video($video->id, $userid);
            if ($managecheck['allowed']) {
                $summary['manageable_videos']++;
            }
        }

        return $summary;
    }

    /**
     * Get videos accessible to user.
     *
     * @param int $userid User ID
     * @return array Accessible videos
     */
    private static function get_accessible_videos($userid) {
        global $DB;

        // Get courses user is enrolled in
        $enrolledcourses = enrol_get_users_courses($userid, true);
        $courseids = array_keys($enrolledcourses);

        if (empty($courseids)) {
            return [];
        }

        // Get videos from enrolled courses
        list($insql, $params) = $DB->get_in_or_equal($courseids);
        $sql = "SELECT * FROM {" . video_manager::TABLE_VIDEOS . "} 
                WHERE course_id $insql 
                AND status = ?
                ORDER BY upload_date DESC";
        
        $params[] = video_manager::STATUS_READY;
        
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Log access attempt.
     *
     * @param int $videoid Video record ID
     * @param int $userid User ID
     * @param string $action Action attempted
     * @param bool $allowed Whether access was allowed
     * @param string $reason Reason for decision
     */
    public static function log_access_attempt($videoid, $userid, $action, $allowed, $reason) {
        // For now, just use debugging. In production, this could log to a separate table
        $status = $allowed ? 'ALLOWED' : 'DENIED';
        debugging("Video access {$status}: User {$userid}, Video {$videoid}, Action {$action}, Reason: {$reason}", DEBUG_DEVELOPER);
    }

    /**
     * Clear permission cache.
     *
     * @param int $userid Optional user ID to clear specific user cache
     */
    public static function clear_permission_cache($userid = null) {
        if ($userid) {
            foreach (array_keys(self::$permissioncache) as $key) {
                if (strpos($key, "_{$userid}") !== false) {
                    unset(self::$permissioncache[$key]);
                }
            }
        } else {
            self::$permissioncache = [];
        }
    }

    /**
     * Get access statistics.
     *
     * @param int $days Number of days to look back
     * @return array Access statistics
     */
    public static function get_access_statistics($days = 30) {
        // This would typically query access logs
        // For now, return basic token statistics
        return token_manager::get_token_statistics($days);
    }
}