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
 * Player handler for Cloudflare Stream video integration.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream\handlers;

use local_cloudflarestream\video_manager;
use local_cloudflarestream\access_controller;
use local_cloudflarestream\config_manager;
use local_cloudflarestream\auth\token_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles Cloudflare Stream player integration and rendering.
 */
class player_handler {

    /** @var string Default player width */
    const DEFAULT_WIDTH = '100%';

    /** @var string Default player height */
    const DEFAULT_HEIGHT = '400px';

    /** @var string Cloudflare Stream embed domain */
    const EMBED_DOMAIN = 'embed.cloudflarestream.com';

    /**
     * Generate Cloudflare Stream player HTML.
     *
     * @param int $videoid Video record ID
     * @param array $options Player options
     * @return array Player generation result
     */
    public static function generate_player($videoid, $options = []) {
        global $USER;

        try {
            // Get video record
            $video = video_manager::get_video($videoid);
            if (!$video) {
                return [
                    'success' => false,
                    'error' => get_string('error_video_not_found', 'local_cloudflarestream')
                ];
            }

            // Check if video is ready
            if ($video->status !== video_manager::STATUS_READY) {
                return self::generate_status_player($video, $options);
            }

            // Check user access
            $accesscheck = access_controller::can_view_video($videoid, $USER->id);
            if (!$accesscheck['allowed']) {
                return [
                    'success' => false,
                    'error' => $accesscheck['message']
                ];
            }

            // Generate access token
            $tokenresult = token_manager::generate_video_token($USER->id, $videoid, $options);
            if (!$tokenresult['success']) {
                return [
                    'success' => false,
                    'error' => $tokenresult['error']
                ];
            }

            // Generate player HTML
            $playerhtml = self::render_cloudflare_player($video, $tokenresult, $options);

            return [
                'success' => true,
                'html' => $playerhtml,
                'video_id' => $video->cloudflare_video_id,
                'token_expires' => $tokenresult['expires_at']
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Render Cloudflare Stream player HTML.
     *
     * @param \stdClass $video Video record
     * @param array $tokenresult Token generation result
     * @param array $options Player options
     * @return string Player HTML
     */
    private static function render_cloudflare_player($video, $tokenresult, $options) {
        global $OUTPUT;

        // Prepare player configuration
        $playerconfig = self::get_player_config($options);
        $playerid = 'cloudflare-player-' . uniqid();

        // Template context
        $context = [
            'player_id' => $playerid,
            'video_id' => $video->cloudflare_video_id,
            'width' => $options['width'] ?? self::DEFAULT_WIDTH,
            'height' => $options['height'] ?? self::DEFAULT_HEIGHT,
            'poster' => $video->thumbnail_url ?? '',
            'title' => self::get_video_title($video),
            'controls' => $playerconfig['controls'],
            'autoplay' => $playerconfig['autoplay'],
            'muted' => $playerconfig['muted'],
            'loop' => $playerconfig['loop'],
            'preload' => $playerconfig['preload'],
            'responsive' => $options['responsive'] ?? true,
            'token_expires' => $tokenresult['expires_at'],
            'video_record_id' => $video->id
        ];

        // Add JavaScript for player initialization
        self::add_player_javascript($playerid, $video, $tokenresult, $playerconfig);

        return $OUTPUT->render_from_template('local_cloudflarestream/player', $context);
    }

    /**
     * Generate status player for videos not ready.
     *
     * @param \stdClass $video Video record
     * @param array $options Player options
     * @return array Status player result
     */
    private static function generate_status_player($video, $options) {
        global $OUTPUT;

        $statusmessage = self::get_status_message($video->status);
        $progress = self::get_status_progress($video->status);

        $context = [
            'video_id' => $video->id,
            'status' => $video->status,
            'status_message' => $statusmessage,
            'progress' => $progress,
            'width' => $options['width'] ?? self::DEFAULT_WIDTH,
            'height' => $options['height'] ?? self::DEFAULT_HEIGHT,
            'title' => self::get_video_title($video),
            'error_message' => $video->error_message
        ];

        $html = $OUTPUT->render_from_template('local_cloudflarestream/player_status', $context);

        return [
            'success' => true,
            'html' => $html,
            'status' => $video->status
        ];
    }

    /**
     * Get player configuration.
     *
     * @param array $options User options
     * @return array Player configuration
     */
    private static function get_player_config($options) {
        return [
            'controls' => $options['controls'] ?? config_manager::get('player_controls', true),
            'autoplay' => $options['autoplay'] ?? config_manager::get('autoplay', false),
            'muted' => $options['muted'] ?? false,
            'loop' => $options['loop'] ?? false,
            'preload' => $options['preload'] ?? 'metadata'
        ];
    }

    /**
     * Add JavaScript for player initialization.
     *
     * @param string $playerid Player element ID
     * @param \stdClass $video Video record
     * @param array $tokenresult Token result
     * @param array $config Player configuration
     */
    private static function add_player_javascript($playerid, $video, $tokenresult, $config) {
        global $PAGE;

        $jsconfig = [
            'playerId' => $playerid,
            'videoId' => $video->cloudflare_video_id,
            'videoRecordId' => $video->id,
            'tokenExpires' => $tokenresult['expires_at'],
            'config' => $config,
            'endpoints' => [
                'token_refresh' => new \moodle_url('/local/cloudflarestream/ajax/refresh_token.php'),
                'status_check' => new \moodle_url('/local/cloudflarestream/upload_status.php')
            ]
        ];

        $PAGE->requires->js_call_amd('local_cloudflarestream/player', 'init', [$jsconfig]);
    }

    /**
     * Get video title from metadata.
     *
     * @param \stdClass $video Video record
     * @return string Video title
     */
    private static function get_video_title($video) {
        $metadata = json_decode($video->metadata ?: '{}', true);
        return $metadata['original_filename'] ?? $metadata['name'] ?? 'Video';
    }

    /**
     * Get status message for video status.
     *
     * @param string $status Video status
     * @return string Status message
     */
    private static function get_status_message($status) {
        switch ($status) {
            case video_manager::STATUS_PENDING:
                return get_string('status_pending', 'local_cloudflarestream');
            case video_manager::STATUS_UPLOADING:
                return get_string('status_uploading', 'local_cloudflarestream');
            case video_manager::STATUS_PROCESSING:
                return get_string('status_processing', 'local_cloudflarestream');
            case video_manager::STATUS_ERROR:
                return get_string('status_error', 'local_cloudflarestream');
            default:
                return 'Unknown status';
        }
    }

    /**
     * Get progress percentage for status.
     *
     * @param string $status Video status
     * @return int Progress percentage
     */
    private static function get_status_progress($status) {
        switch ($status) {
            case video_manager::STATUS_PENDING:
                return 0;
            case video_manager::STATUS_UPLOADING:
                return 25;
            case video_manager::STATUS_PROCESSING:
                return 75;
            case video_manager::STATUS_ERROR:
                return 0;
            default:
                return 0;
        }
    }

    /**
     * Generate embed URL for Cloudflare Stream.
     *
     * @param string $cloudflarevideoid Cloudflare video ID
     * @param array $options Embed options
     * @return string Embed URL
     */
    public static function generate_embed_url($cloudflarevideoid, $options = []) {
        $params = [];

        // Add player configuration parameters
        if (isset($options['autoplay']) && $options['autoplay']) {
            $params['autoplay'] = 'true';
        }

        if (isset($options['controls']) && !$options['controls']) {
            $params['controls'] = 'false';
        }

        if (isset($options['muted']) && $options['muted']) {
            $params['muted'] = 'true';
        }

        if (isset($options['loop']) && $options['loop']) {
            $params['loop'] = 'true';
        }

        if (isset($options['preload'])) {
            $params['preload'] = $options['preload'];
        }

        $querystring = !empty($params) ? '?' . http_build_query($params) : '';
        
        return 'https://' . self::EMBED_DOMAIN . '/' . $cloudflarevideoid . $querystring;
    }

    /**
     * Generate iframe embed code.
     *
     * @param int $videoid Video record ID
     * @param array $options Embed options
     * @return array Embed generation result
     */
    public static function generate_embed_code($videoid, $options = []) {
        global $USER;

        try {
            $video = video_manager::get_video($videoid);
            if (!$video || $video->status !== video_manager::STATUS_READY) {
                return [
                    'success' => false,
                    'error' => 'Video not ready for embedding'
                ];
            }

            // Check access
            $accesscheck = access_controller::can_view_video($videoid, $USER->id);
            if (!$accesscheck['allowed']) {
                return [
                    'success' => false,
                    'error' => $accesscheck['message']
                ];
            }

            $embedurl = self::generate_embed_url($video->cloudflare_video_id, $options);
            $width = $options['width'] ?? self::DEFAULT_WIDTH;
            $height = $options['height'] ?? self::DEFAULT_HEIGHT;

            $iframe = sprintf(
                '<iframe src="%s" width="%s" height="%s" frameborder="0" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;" allowfullscreen="true"></iframe>',
                htmlspecialchars($embedurl),
                htmlspecialchars($width),
                htmlspecialchars($height)
            );

            return [
                'success' => true,
                'embed_code' => $iframe,
                'embed_url' => $embedurl
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Replace video files with Cloudflare Stream players in content.
     *
     * @param string $content HTML content
     * @param int $contextid Context ID for file lookup
     * @return string Modified content
     */
    public static function replace_video_files_in_content($content, $contextid) {
        // Look for video file references in content
        $pattern = '/@@PLUGINFILE@@\/([^"\'>\s]+\.(mp4|mov|avi|mkv|webm))/i';
        
        return preg_replace_callback($pattern, function($matches) use ($contextid) {
            $filename = $matches[1];
            
            // Try to find corresponding Cloudflare Stream video
            $video = self::find_video_by_filename($filename, $contextid);
            if ($video && $video->status === video_manager::STATUS_READY) {
                // Replace with Cloudflare Stream player
                $playerresult = self::generate_player($video->id, ['responsive' => true]);
                if ($playerresult['success']) {
                    return $playerresult['html'];
                }
            }
            
            // Return original if no replacement found
            return $matches[0];
        }, $content);
    }

    /**
     * Find video by filename in context.
     *
     * @param string $filename Filename to search for
     * @param int $contextid Context ID
     * @return \stdClass|null Video record or null
     */
    private static function find_video_by_filename($filename, $contextid) {
        global $DB;

        // Get context
        $context = \context::instance_by_id($contextid);
        $courseid = null;

        if ($context->contextlevel == CONTEXT_COURSE) {
            $courseid = $context->instanceid;
        } else {
            $coursecontext = $context->get_course_context(false);
            $courseid = $coursecontext ? $coursecontext->instanceid : null;
        }

        if (!$courseid) {
            return null;
        }

        // Search for video with matching filename
        $videos = video_manager::get_videos_by_course($courseid);
        
        foreach ($videos as $video) {
            $metadata = json_decode($video->metadata ?: '{}', true);
            $originalfilename = $metadata['original_filename'] ?? '';
            
            if (basename($originalfilename) === basename($filename)) {
                return $video;
            }
        }

        return null;
    }

    /**
     * Get player statistics.
     *
     * @param int $videoid Video record ID
     * @return array Player statistics
     */
    public static function get_player_statistics($videoid) {
        // This would typically integrate with Cloudflare Stream Analytics API
        // For now, return basic information
        $video = video_manager::get_video($videoid);
        if (!$video) {
            return ['error' => 'Video not found'];
        }

        return [
            'video_id' => $videoid,
            'cloudflare_video_id' => $video->cloudflare_video_id,
            'status' => $video->status,
            'duration' => $video->duration,
            'upload_date' => $video->upload_date,
            'ready_date' => $video->ready_date
        ];
    }

    /**
     * Validate player domain restrictions.
     *
     * @param string $domain Domain to validate
     * @return bool True if domain is allowed
     */
    public static function is_domain_allowed($domain) {
        global $CFG;

        // Always allow the site's own domain
        $sitedomain = parse_url($CFG->wwwroot, PHP_URL_HOST);
        if ($domain === $sitedomain) {
            return true;
        }

        // Check configured allowed domains
        $alloweddomains = config_manager::get('allowed_domains', '');
        if (empty($alloweddomains)) {
            return true; // No restrictions configured
        }

        $domains = array_map('trim', explode(',', $alloweddomains));
        return in_array($domain, $domains);
    }

    /**
     * Validate player access with security checks.
     *
     * @param int $videoid Video record ID
     * @param string $token Access token
     * @param array $securityoptions Security options
     * @return array Validation result
     */
    public static function validate_player_access($videoid, $token, $securityoptions = []) {
        // Validate token
        $tokenvalidation = access_controller::validate_video_access($token, $videoid);
        if (!$tokenvalidation['valid']) {
            return [
                'valid' => false,
                'error' => $tokenvalidation['error']
            ];
        }

        // Check domain restrictions
        if (isset($securityoptions['check_domain']) && $securityoptions['check_domain']) {
            $domain = $_SERVER['HTTP_HOST'] ?? '';
            if (!self::is_domain_allowed($domain)) {
                return [
                    'valid' => false,
                    'error' => 'Domain not allowed for video playback'
                ];
            }
        }

        // Check referrer restrictions
        if (isset($securityoptions['check_referrer']) && $securityoptions['check_referrer']) {
            $referrer = $_SERVER['HTTP_REFERER'] ?? '';
            if (!self::is_referrer_allowed($referrer)) {
                return [
                    'valid' => false,
                    'error' => 'Referrer not allowed for video playback'
                ];
            }
        }

        // Check IP restrictions
        if (isset($securityoptions['check_ip']) && $securityoptions['check_ip']) {
            $clientip = getremoteaddr();
            if (!self::is_ip_allowed($clientip, $tokenvalidation)) {
                return [
                    'valid' => false,
                    'error' => 'IP address not allowed for video playback'
                ];
            }
        }

        return [
            'valid' => true,
            'user_id' => $tokenvalidation['user_id'],
            'permissions' => $tokenvalidation['permissions']
        ];
    }

    /**
     * Check if referrer is allowed.
     *
     * @param string $referrer Referrer URL
     * @return bool True if referrer is allowed
     */
    private static function is_referrer_allowed($referrer) {
        global $CFG;

        if (empty($referrer)) {
            return true; // Allow empty referrer
        }

        $referrerhost = parse_url($referrer, PHP_URL_HOST);
        $sitehost = parse_url($CFG->wwwroot, PHP_URL_HOST);

        // Always allow same domain
        if ($referrerhost === $sitehost) {
            return true;
        }

        // Check configured allowed referrers
        $allowedreferrers = config_manager::get('allowed_referrers', '');
        if (empty($allowedreferrers)) {
            return true; // No restrictions configured
        }

        $referrers = array_map('trim', explode(',', $allowedreferrers));
        return in_array($referrerhost, $referrers);
    }

    /**
     * Check if IP is allowed based on token restrictions.
     *
     * @param string $clientip Client IP address
     * @param array $tokenvalidation Token validation result
     * @return bool True if IP is allowed
     */
    private static function is_ip_allowed($clientip, $tokenvalidation) {
        // If token has IP restriction, check it
        if (isset($tokenvalidation['payload']['ip'])) {
            return $tokenvalidation['payload']['ip'] === $clientip;
        }

        // No IP restriction in token
        return true;
    }

    /**
     * Generate secure player with enhanced security.
     *
     * @param int $videoid Video record ID
     * @param array $options Player options
     * @param array $securityoptions Security options
     * @return array Player generation result
     */
    public static function generate_secure_player($videoid, $options = [], $securityoptions = []) {
        // Add security options to token generation
        $tokenoptions = $options;
        if (isset($securityoptions['ip_restriction']) && $securityoptions['ip_restriction']) {
            $tokenoptions['ip_restriction'] = true;
        }
        if (isset($securityoptions['user_agent_restriction']) && $securityoptions['user_agent_restriction']) {
            $tokenoptions['user_agent_restriction'] = true;
        }

        // Generate player with security options
        $result = self::generate_player($videoid, $tokenoptions);
        
        if ($result['success']) {
            // Add security metadata to player
            $result['security'] = [
                'domain_restricted' => $securityoptions['check_domain'] ?? false,
                'referrer_restricted' => $securityoptions['check_referrer'] ?? false,
                'ip_restricted' => $securityoptions['ip_restriction'] ?? false,
                'user_agent_restricted' => $securityoptions['user_agent_restriction'] ?? false
            ];
        }

        return $result;
    }

    /**
     * Create fallback player for when Cloudflare Stream fails.
     *
     * @param int $videoid Video record ID
     * @param array $options Player options
     * @return array Fallback player result
     */
    public static function generate_fallback_player($videoid, $options = []) {
        global $OUTPUT;

        $video = video_manager::get_video($videoid);
        if (!$video) {
            return [
                'success' => false,
                'error' => 'Video not found'
            ];
        }

        // Try to get original file if still available
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($video->moodle_file_id);

        if ($file && !$file->is_directory()) {
            // Generate HTML5 video player for local file
            $fileurl = \moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename()
            );

            $context = [
                'video_url' => $fileurl->out(),
                'width' => $options['width'] ?? self::DEFAULT_WIDTH,
                'height' => $options['height'] ?? self::DEFAULT_HEIGHT,
                'title' => self::get_video_title($video),
                'controls' => $options['controls'] ?? true,
                'autoplay' => $options['autoplay'] ?? false,
                'poster' => $video->thumbnail_url ?? ''
            ];

            $html = $OUTPUT->render_from_template('local_cloudflarestream/fallback_player', $context);

            return [
                'success' => true,
                'html' => $html,
                'type' => 'fallback'
            ];
        }

        // No fallback available
        return [
            'success' => false,
            'error' => 'No fallback player available'
        ];
    }

    /**
     * Log player access attempt.
     *
     * @param int $videoid Video record ID
     * @param int $userid User ID
     * @param bool $success Whether access was successful
     * @param string $reason Reason for success/failure
     * @param array $metadata Additional metadata
     */
    public static function log_player_access($videoid, $userid, $success, $reason, $metadata = []) {
        // Log to Moodle logs
        $logdata = [
            'video_id' => $videoid,
            'user_id' => $userid,
            'success' => $success,
            'reason' => $reason,
            'ip_address' => getremoteaddr(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'timestamp' => time(),
            'metadata' => $metadata
        ];

        // Use Moodle's logging system
        debugging('Cloudflare Stream player access: ' . json_encode($logdata), DEBUG_DEVELOPER);

        // Could also store in custom table for detailed analytics
        access_controller::log_access_attempt($videoid, $userid, 'player_access', $success, $reason);
    }

    /**
     * Check for suspicious player activity.
     *
     * @param int $userid User ID
     * @param int $videoid Video record ID
     * @return array Suspicion check result
     */
    public static function check_suspicious_activity($userid, $videoid) {
        $cache = \cache::make('local_cloudflarestream', 'tokens');
        $cachekey = "player_access_{$userid}_{$videoid}";
        
        $accesscount = $cache->get($cachekey) ?: 0;
        $accesscount++;
        
        // Allow max 10 player loads per video per user per hour
        if ($accesscount > 10) {
            return [
                'suspicious' => true,
                'reason' => 'Excessive player access attempts',
                'count' => $accesscount
            ];
        }

        $cache->set($cachekey, $accesscount, 3600); // 1 hour TTL

        return [
            'suspicious' => false,
            'count' => $accesscount
        ];
    }

    /**
     * Generate Content Security Policy for player.
     *
     * @return string CSP header value
     */
    public static function get_player_csp() {
        $csp = [
            "default-src 'self'",
            "frame-src 'self' https://" . self::EMBED_DOMAIN,
            "connect-src 'self' https://" . self::EMBED_DOMAIN,
            "img-src 'self' https://" . self::EMBED_DOMAIN . " data:",
            "media-src 'self' https://" . self::EMBED_DOMAIN,
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'"
        ];

        return implode('; ', $csp);
    }

    /**
     * Validate player embed context.
     *
     * @param int $videoid Video record ID
     * @param int $contextid Context ID where player is embedded
     * @return array Validation result
     */
    public static function validate_embed_context($videoid, $contextid) {
        $video = video_manager::get_video($videoid);
        if (!$video) {
            return [
                'valid' => false,
                'error' => 'Video not found'
            ];
        }

        try {
            $context = \context::instance_by_id($contextid);
            $coursecontext = $context->get_course_context(false);
            
            if (!$coursecontext) {
                return [
                    'valid' => false,
                    'error' => 'Invalid context for video embedding'
                ];
            }

            // Check if video belongs to the same course
            if ($video->course_id != $coursecontext->instanceid) {
                return [
                    'valid' => false,
                    'error' => 'Video cannot be embedded in this context'
                ];
            }

            return [
                'valid' => true,
                'course_id' => $coursecontext->instanceid
            ];

        } catch (\Exception $e) {
            return [
                'valid' => false,
                'error' => 'Context validation failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate responsive player CSS.
     *
     * @return string CSS for responsive player
     */
    public static function get_responsive_css() {
        return '
        .cloudflare-stream-container {
            position: relative;
            width: 100%;
            height: 0;
            padding-bottom: 56.25%; /* 16:9 aspect ratio */
        }
        
        .cloudflare-stream-container iframe,
        .cloudflare-stream-container video {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
        
        .cloudflare-stream-status {
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            min-height: 200px;
            text-align: center;
        }
        
        .cloudflare-stream-progress {
            width: 100%;
            max-width: 300px;
            margin: 1rem 0;
        }
        ';
    }
}