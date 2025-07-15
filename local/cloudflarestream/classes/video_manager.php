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
 * Video database manager class.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages video records in the database.
 */
class video_manager {

    /** @var string Table name for videos */
    const TABLE_VIDEOS = 'local_cloudflarestream_videos';

    /** @var string Table name for processing queue */
    const TABLE_QUEUE = 'local_cloudflarestream_queue';

    // Video status constants
    const STATUS_PENDING = 'pending';
    const STATUS_UPLOADING = 'uploading';
    const STATUS_PROCESSING = 'processing';
    const STATUS_READY = 'ready';
    const STATUS_ERROR = 'error';

    /**
     * Create a new video record.
     *
     * @param int $moodlefileid Moodle file ID
     * @param int $courseid Course ID
     * @param int $userid User ID who uploaded
     * @param int $filesize File size in bytes
     * @param array $metadata Additional metadata
     * @return int Video record ID
     */
    public static function create_video($moodlefileid, $courseid, $userid, $filesize, $metadata = []) {
        global $DB;

        $record = new \stdClass();
        $record->moodle_file_id = $moodlefileid;
        $record->course_id = $courseid;
        $record->user_id = $userid;
        $record->status = self::STATUS_PENDING;
        $record->upload_date = time();
        $record->file_size = $filesize;
        $record->metadata = json_encode($metadata);
        $record->timecreated = time();
        $record->timemodified = time();

        return $DB->insert_record(self::TABLE_VIDEOS, $record);
    }

    /**
     * Update video record.
     *
     * @param int $videoid Video record ID
     * @param array $data Data to update
     * @return bool Success
     */
    public static function update_video($videoid, $data) {
        global $DB;

        $data['timemodified'] = time();
        
        // Handle status-specific timestamps
        if (isset($data['status'])) {
            switch ($data['status']) {
                case self::STATUS_PROCESSING:
                    $data['processing_date'] = time();
                    break;
                case self::STATUS_READY:
                    $data['ready_date'] = time();
                    break;
            }
        }

        return $DB->update_record(self::TABLE_VIDEOS, (object)array_merge(['id' => $videoid], $data));
    }

    /**
     * Get video record by ID.
     *
     * @param int $videoid Video record ID
     * @return \stdClass|false Video record or false if not found
     */
    public static function get_video($videoid) {
        global $DB;
        return $DB->get_record(self::TABLE_VIDEOS, ['id' => $videoid]);
    }

    /**
     * Get video record by Moodle file ID.
     *
     * @param int $moodlefileid Moodle file ID
     * @return \stdClass|false Video record or false if not found
     */
    public static function get_video_by_file($moodlefileid) {
        global $DB;
        return $DB->get_record(self::TABLE_VIDEOS, ['moodle_file_id' => $moodlefileid]);
    }

    /**
     * Get video record by Cloudflare video ID.
     *
     * @param string $cloudflarevideoid Cloudflare video ID
     * @return \stdClass|false Video record or false if not found
     */
    public static function get_video_by_cloudflare_id($cloudflarevideoid) {
        global $DB;
        return $DB->get_record(self::TABLE_VIDEOS, ['cloudflare_video_id' => $cloudflarevideoid]);
    }

    /**
     * Get videos by status.
     *
     * @param string $status Video status
     * @param int $limit Maximum number of records
     * @return array Video records
     */
    public static function get_videos_by_status($status, $limit = 0) {
        global $DB;
        return $DB->get_records(self::TABLE_VIDEOS, ['status' => $status], 'upload_date ASC', '*', 0, $limit);
    }

    /**
     * Get videos by course.
     *
     * @param int $courseid Course ID
     * @param string $status Optional status filter
     * @return array Video records
     */
    public static function get_videos_by_course($courseid, $status = null) {
        global $DB;
        
        $conditions = ['course_id' => $courseid];
        if ($status !== null) {
            $conditions['status'] = $status;
        }
        
        return $DB->get_records(self::TABLE_VIDEOS, $conditions, 'upload_date DESC');
    }

    /**
     * Get videos by user.
     *
     * @param int $userid User ID
     * @param string $status Optional status filter
     * @return array Video records
     */
    public static function get_videos_by_user($userid, $status = null) {
        global $DB;
        
        $conditions = ['user_id' => $userid];
        if ($status !== null) {
            $conditions['status'] = $status;
        }
        
        return $DB->get_records(self::TABLE_VIDEOS, $conditions, 'upload_date DESC');
    }

    /**
     * Delete video record.
     *
     * @param int $videoid Video record ID
     * @return bool Success
     */
    public static function delete_video($videoid) {
        global $DB;
        return $DB->delete_records(self::TABLE_VIDEOS, ['id' => $videoid]);
    }

    /**
     * Get video statistics.
     *
     * @return array Statistics
     */
    public static function get_statistics() {
        global $DB;

        $stats = [];
        $stats['total'] = $DB->count_records(self::TABLE_VIDEOS);
        $stats['pending'] = $DB->count_records(self::TABLE_VIDEOS, ['status' => self::STATUS_PENDING]);
        $stats['uploading'] = $DB->count_records(self::TABLE_VIDEOS, ['status' => self::STATUS_UPLOADING]);
        $stats['processing'] = $DB->count_records(self::TABLE_VIDEOS, ['status' => self::STATUS_PROCESSING]);
        $stats['ready'] = $DB->count_records(self::TABLE_VIDEOS, ['status' => self::STATUS_READY]);
        $stats['error'] = $DB->count_records(self::TABLE_VIDEOS, ['status' => self::STATUS_ERROR]);

        // Calculate total storage used
        $sql = "SELECT SUM(file_size) as total_size FROM {" . self::TABLE_VIDEOS . "} WHERE status != ?";
        $result = $DB->get_record_sql($sql, [self::STATUS_ERROR]);
        $stats['storage_used'] = $result->total_size ?: 0;

        return $stats;
    }

    /**
     * Add item to processing queue.
     *
     * @param int $videoid Video record ID
     * @param string $action Action to perform
     * @param int $priority Priority (1-10, lower is higher priority)
     * @param array $data Additional data
     * @return int Queue record ID
     */
    public static function queue_action($videoid, $action, $priority = 5, $data = []) {
        global $DB;

        $record = new \stdClass();
        $record->video_id = $videoid;
        $record->action = $action;
        $record->priority = $priority;
        $record->attempts = 0;
        $record->max_attempts = 3;
        $record->next_attempt = time();
        $record->data = json_encode($data);
        $record->timecreated = time();
        $record->timemodified = time();

        return $DB->insert_record(self::TABLE_QUEUE, $record);
    }

    /**
     * Get next items from processing queue.
     *
     * @param int $limit Maximum number of items
     * @return array Queue records
     */
    public static function get_queue_items($limit = 10) {
        global $DB;

        $sql = "SELECT * FROM {" . self::TABLE_QUEUE . "} 
                WHERE next_attempt <= ? AND attempts < max_attempts 
                ORDER BY priority ASC, timecreated ASC";
        
        return $DB->get_records_sql($sql, [time()], 0, $limit);
    }

    /**
     * Update queue item.
     *
     * @param int $queueid Queue record ID
     * @param array $data Data to update
     * @return bool Success
     */
    public static function update_queue_item($queueid, $data) {
        global $DB;

        $data['timemodified'] = time();
        return $DB->update_record(self::TABLE_QUEUE, (object)array_merge(['id' => $queueid], $data));
    }

    /**
     * Remove item from queue.
     *
     * @param int $queueid Queue record ID
     * @return bool Success
     */
    public static function remove_queue_item($queueid) {
        global $DB;
        return $DB->delete_records(self::TABLE_QUEUE, ['id' => $queueid]);
    }

    /**
     * Clean up old queue items.
     *
     * @param int $maxage Maximum age in seconds (default: 7 days)
     * @return int Number of records deleted
     */
    public static function cleanup_queue($maxage = 604800) {
        global $DB;

        $cutoff = time() - $maxage;
        return $DB->delete_records_select(self::TABLE_QUEUE, 'timecreated < ? AND attempts >= max_attempts', [$cutoff]);
    }
}