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
 * Upload handler for intercepting video file uploads.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream\handlers;

use local_cloudflarestream\video_manager;
use local_cloudflarestream\config_manager;
use local_cloudflarestream\api\stream_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles video file upload interception and processing.
 */
class upload_handler {

    /** @var array Supported video MIME types */
    const VIDEO_MIME_TYPES = [
        'video/mp4',
        'video/quicktime',
        'video/x-msvideo',
        'video/x-matroska',
        'video/webm',
        'video/x-flv',
        'video/x-ms-wmv',
        'video/mp4v-es'
    ];

    /**
     * Handle file upload event.
     *
     * @param \core\event\base $event File upload event
     */
    public static function handle_file_upload($event) {
        global $USER;

        // Check if plugin is configured
        if (!config_manager::is_configured()) {
            return;
        }

        // Get event data
        $eventdata = $event->get_data();
        $fileid = $eventdata['objectid'] ?? null;

        if (!$fileid) {
            return;
        }

        // Get file record
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($fileid);

        if (!$file || $file->is_directory()) {
            return;
        }

        // Check if it's a video file
        if (!self::is_video_file($file)) {
            return;
        }

        // Validate file for Cloudflare Stream
        $validation = self::validate_video_for_upload($file);
        if (!$validation['valid']) {
            debugging('Cloudflare Stream: Video file validation failed: ' . $validation['error']);
            return;
        }

        // Get context information
        $context = \context::instance_by_id($eventdata['contextid']);
        $courseid = self::get_course_id_from_context($context);

        if (!$courseid) {
            debugging('Cloudflare Stream: Could not determine course ID for file upload');
            return;
        }

        // Create video record
        $metadata = [
            'name' => $file->get_filename(),
            'mimetype' => $file->get_mimetype(),
            'original_filename' => $file->get_filename(),
            'context_id' => $context->id,
            'component' => $file->get_component(),
            'filearea' => $file->get_filearea(),
            'itemid' => $file->get_itemid()
        ];

        try {
            $videoid = video_manager::create_video(
                $fileid,
                $courseid,
                $USER->id,
                $file->get_filesize(),
                $metadata
            );

            // Queue for background upload
            video_manager::queue_action($videoid, 'upload', 5, [
                'file_path' => self::get_file_path($file),
                'metadata' => $metadata
            ]);

            debugging('Cloudflare Stream: Video queued for upload - ID: ' . $videoid, DEBUG_DEVELOPER);

        } catch (\Exception $e) {
            debugging('Cloudflare Stream: Failed to create video record: ' . $e->getMessage());
        }
    }

    /**
     * Check if file is a video.
     *
     * @param \stored_file $file File object
     * @return bool True if video file
     */
    public static function is_video_file($file) {
        // Check MIME type
        $mimetype = $file->get_mimetype();
        if (in_array($mimetype, self::VIDEO_MIME_TYPES)) {
            return true;
        }

        // Check file extension as fallback
        $filename = $file->get_filename();
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $supportedformats = explode(',', config_manager::get('supported_formats', 'mp4,mov,avi,mkv,webm'));
        $supportedformats = array_map('trim', $supportedformats);

        return in_array($extension, $supportedformats);
    }

    /**
     * Validate video file for Cloudflare Stream upload.
     *
     * @param \stored_file $file File object
     * @return array Validation result
     */
    public static function validate_video_for_upload($file) {
        // Check file size
        $maxsize = config_manager::get('max_file_size', 524288000);
        if ($file->get_filesize() > $maxsize) {
            return [
                'valid' => false,
                'error' => 'File size exceeds maximum allowed: ' . $file->get_filesize() . ' > ' . $maxsize
            ];
        }

        // Check if file is readable
        if (!$file->get_content_file_handle()) {
            return [
                'valid' => false,
                'error' => 'File is not readable'
            ];
        }

        // Additional checks can be added here
        return ['valid' => true];
    }

    /**
     * Get course ID from context.
     *
     * @param \context $context Context object
     * @return int|null Course ID or null if not found
     */
    private static function get_course_id_from_context($context) {
        switch ($context->contextlevel) {
            case CONTEXT_COURSE:
                return $context->instanceid;
            
            case CONTEXT_MODULE:
                $cm = get_coursemodule_from_id('', $context->instanceid);
                return $cm ? $cm->course : null;
            
            case CONTEXT_BLOCK:
                $parentcontext = $context->get_parent_context();
                return self::get_course_id_from_context($parentcontext);
            
            default:
                // Try to get course context from parent contexts
                $coursecontext = $context->get_course_context(false);
                return $coursecontext ? $coursecontext->instanceid : null;
        }
    }

    /**
     * Get file system path for stored file.
     *
     * @param \stored_file $file File object
     * @return string File path
     */
    private static function get_file_path($file) {
        // Create temporary file path
        $tempdir = make_temp_directory('cloudflarestream');
        $tempfile = $tempdir . '/' . $file->get_contenthash() . '_' . $file->get_filename();
        
        // Copy file content to temporary location
        $file->copy_content_to($tempfile);
        
        return $tempfile;
    }

    /**
     * Process upload queue item.
     *
     * @param \stdClass $queueitem Queue item record
     * @return array Processing result
     */
    public static function process_upload_queue_item($queueitem) {
        try {
            $data = json_decode($queueitem->data, true);
            $filepath = $data['file_path'] ?? null;
            $metadata = $data['metadata'] ?? [];

            if (!$filepath || !file_exists($filepath)) {
                return [
                    'success' => false,
                    'error' => 'File not found: ' . $filepath,
                    'retry' => false
                ];
            }

            // Get stream manager
            $streammanager = stream_manager::get_instance();
            if (!$streammanager) {
                return [
                    'success' => false,
                    'error' => 'Stream manager not available (check configuration)',
                    'retry' => true
                ];
            }

            // Upload video
            $result = $streammanager->upload_video($filepath, $queueitem->video_id, $metadata);

            // Clean up temporary file
            if (file_exists($filepath)) {
                unlink($filepath);
            }

            return [
                'success' => $result['success'],
                'error' => $result['success'] ? '' : $result['error'],
                'retry' => !$result['success']
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'retry' => true
            ];
        }
    }

    /**
     * Get upload statistics.
     *
     * @return array Upload statistics
     */
    public static function get_upload_statistics() {
        $stats = video_manager::get_statistics();
        
        // Add queue statistics
        global $DB;
        $stats['queued_uploads'] = $DB->count_records(video_manager::TABLE_QUEUE, ['action' => 'upload']);
        $stats['failed_queue_items'] = $DB->count_records_select(
            video_manager::TABLE_QUEUE, 
            'action = ? AND attempts >= max_attempts', 
            ['upload']
        );

        return $stats;
    }

    /**
     * Retry failed uploads.
     *
     * @param int $limit Maximum number of uploads to retry
     * @return array Retry results
     */
    public static function retry_failed_uploads($limit = 10) {
        $failedvideos = video_manager::get_videos_by_status(video_manager::STATUS_ERROR, $limit);
        $results = [
            'retried' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($failedvideos as $video) {
            try {
                // Reset video status
                video_manager::update_video($video->id, [
                    'status' => video_manager::STATUS_PENDING,
                    'error_message' => null
                ]);

                // Re-queue for upload
                $metadata = json_decode($video->metadata ?: '{}', true);
                video_manager::queue_action($video->id, 'upload', 5, [
                    'metadata' => $metadata,
                    'retry' => true
                ]);

                $results['retried']++;

            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][$video->id] = $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Clean up temporary files.
     *
     * @param int $maxage Maximum age in seconds
     * @return int Number of files cleaned up
     */
    public static function cleanup_temp_files($maxage = 86400) {
        $tempdir = make_temp_directory('cloudflarestream');
        $cleaned = 0;

        if (is_dir($tempdir)) {
            $files = glob($tempdir . '/*');
            $cutoff = time() - $maxage;

            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < $cutoff) {
                    if (unlink($file)) {
                        $cleaned++;
                    }
                }
            }
        }

        return $cleaned;
    }

    /**
     * Get supported video formats.
     *
     * @return array Supported formats
     */
    public static function get_supported_formats() {
        $formats = config_manager::get('supported_formats', 'mp4,mov,avi,mkv,webm');
        return array_map('trim', explode(',', strtolower($formats)));
    }

    /**
     * Check if upload is enabled for context.
     *
     * @param \context $context Context to check
     * @return bool True if upload enabled
     */
    public static function is_upload_enabled_for_context($context) {
        // For now, enable for all course contexts
        // This can be extended with more granular controls
        $coursecontext = $context->get_course_context(false);
        return $coursecontext !== false;
    }
}