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
 * Scheduled task to clean up local files after successful upload.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream\task;

use local_cloudflarestream\video_manager;
use local_cloudflarestream\config_manager;
use local_cloudflarestream\handlers\upload_handler;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task to clean up local files after successful Cloudflare upload.
 */
class cleanup_files extends \core\task\scheduled_task {

    /**
     * Get task name.
     *
     * @return string Task name
     */
    public function get_name() {
        return get_string('task_cleanup_files', 'local_cloudflarestream');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        mtrace('Starting Cloudflare Stream file cleanup...');

        $cleanupdelay = config_manager::get('cleanup_delay', 604800); // 7 days default
        $cutoff = time() - $cleanupdelay;

        // Get videos that are ready and old enough for cleanup
        $readyvideos = $this->get_videos_for_cleanup($cutoff);

        if (empty($readyvideos)) {
            mtrace('No files to clean up.');
            return;
        }

        mtrace('Found ' . count($readyvideos) . ' videos ready for file cleanup.');

        $cleaned = 0;
        $errors = 0;

        foreach ($readyvideos as $video) {
            try {
                if ($this->cleanup_video_file($video)) {
                    $cleaned++;
                    mtrace("Cleaned up file for video {$video->id}");
                } else {
                    $errors++;
                    mtrace("Failed to clean up file for video {$video->id}");
                }
            } catch (\Exception $e) {
                $errors++;
                mtrace("Exception cleaning up video {$video->id}: " . $e->getMessage());
            }
        }

        // Clean up temporary files
        $tempCleaned = upload_handler::cleanup_temp_files();
        if ($tempCleaned > 0) {
            mtrace("Cleaned up {$tempCleaned} temporary files.");
        }

        mtrace("File cleanup completed. Cleaned: {$cleaned}, Errors: {$errors}");
    }

    /**
     * Get videos ready for file cleanup.
     *
     * @param int $cutoff Timestamp cutoff
     * @return array Video records
     */
    private function get_videos_for_cleanup($cutoff) {
        global $DB;

        $sql = "SELECT v.*, f.contenthash, f.pathnamehash
                FROM {" . video_manager::TABLE_VIDEOS . "} v
                LEFT JOIN {files} f ON f.id = v.moodle_file_id
                WHERE v.status = ? 
                AND v.ready_date IS NOT NULL 
                AND v.ready_date < ?
                AND f.id IS NOT NULL
                ORDER BY v.ready_date ASC";

        return $DB->get_records_sql($sql, [video_manager::STATUS_READY, $cutoff], 0, 100); // Limit to 100 per run
    }

    /**
     * Clean up local file for a video.
     *
     * @param \stdClass $video Video record with file info
     * @return bool Success
     */
    private function cleanup_video_file($video) {
        global $DB;

        // Verify video is still ready on Cloudflare
        if (!$this->verify_video_on_cloudflare($video)) {
            mtrace("Video {$video->id} not ready on Cloudflare, skipping cleanup");
            return false;
        }

        // Get file storage
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($video->moodle_file_id);

        if (!$file) {
            // File already deleted, update our record
            $this->mark_file_cleaned($video->id);
            return true;
        }

        // Check if file is still being used elsewhere
        if ($this->is_file_referenced_elsewhere($file)) {
            mtrace("File {$video->moodle_file_id} is referenced elsewhere, not deleting");
            return false;
        }

        // Create backup reference before deletion (optional)
        $this->create_file_backup_reference($video, $file);

        // Delete the file
        try {
            $file->delete();
            $this->mark_file_cleaned($video->id);
            return true;
        } catch (\Exception $e) {
            mtrace("Failed to delete file {$video->moodle_file_id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify video is ready on Cloudflare.
     *
     * @param \stdClass $video Video record
     * @return bool True if ready
     */
    private function verify_video_on_cloudflare($video) {
        if (!$video->cloudflare_video_id) {
            return false;
        }

        try {
            $streammanager = \local_cloudflarestream\api\stream_manager::get_instance();
            if (!$streammanager) {
                return false;
            }

            $result = $streammanager->get_video_metadata($video->cloudflare_video_id);
            return $result['success'] && 
                   isset($result['data']['status']['state']) && 
                   $result['data']['status']['state'] === 'ready';

        } catch (\Exception $e) {
            mtrace("Failed to verify video {$video->cloudflare_video_id} on Cloudflare: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if file is referenced elsewhere in Moodle.
     *
     * @param \stored_file $file File object
     * @return bool True if referenced elsewhere
     */
    private function is_file_referenced_elsewhere($file) {
        global $DB;

        // Check if file is used in other contexts or activities
        $fs = get_file_storage();
        $references = $fs->get_references_by_storedfile($file);

        return !empty($references);
    }

    /**
     * Create backup reference for deleted file.
     *
     * @param \stdClass $video Video record
     * @param \stored_file $file File object
     */
    private function create_file_backup_reference($video, $file) {
        // Store file metadata for potential recovery
        $metadata = json_decode($video->metadata ?: '{}', true);
        $metadata['deleted_file'] = [
            'filename' => $file->get_filename(),
            'filesize' => $file->get_filesize(),
            'mimetype' => $file->get_mimetype(),
            'contenthash' => $file->get_contenthash(),
            'deleted_at' => time()
        ];

        video_manager::update_video($video->id, [
            'metadata' => json_encode($metadata)
        ]);
    }

    /**
     * Mark file as cleaned in video record.
     *
     * @param int $videoid Video record ID
     */
    private function mark_file_cleaned($videoid) {
        $metadata = [];
        $video = video_manager::get_video($videoid);
        
        if ($video && $video->metadata) {
            $metadata = json_decode($video->metadata, true) ?: [];
        }

        $metadata['file_cleaned'] = true;
        $metadata['file_cleaned_at'] = time();

        video_manager::update_video($videoid, [
            'metadata' => json_encode($metadata)
        ]);
    }

    /**
     * Get cleanup statistics.
     *
     * @return array Cleanup statistics
     */
    public function get_cleanup_statistics() {
        global $DB;

        $stats = [];
        
        // Videos ready for cleanup
        $cleanupdelay = config_manager::get('cleanup_delay', 604800);
        $cutoff = time() - $cleanupdelay;
        
        $stats['ready_for_cleanup'] = $DB->count_records_select(
            video_manager::TABLE_VIDEOS,
            'status = ? AND ready_date IS NOT NULL AND ready_date < ?',
            [video_manager::STATUS_READY, $cutoff]
        );

        // Videos with files already cleaned
        $stats['files_cleaned'] = $DB->count_records_select(
            video_manager::TABLE_VIDEOS,
            "status = ? AND " . $DB->sql_like('metadata', '?'),
            [video_manager::STATUS_READY, '%"file_cleaned":true%']
        );

        return $stats;
    }
}