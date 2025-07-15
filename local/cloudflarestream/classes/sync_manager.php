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
 * Manual synchronization manager for Cloudflare Stream.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream;

use local_cloudflarestream\api\stream_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Provides manual synchronization tools for administrators.
 */
class sync_manager {

    /**
     * Perform manual full synchronization.
     *
     * @param bool $force Force sync even for ready videos
     * @return array Sync results
     */
    public static function manual_full_sync($force = false) {
        global $DB;

        $results = [
            'success' => true,
            'total_processed' => 0,
            'updated' => 0,
            'errors' => 0,
            'details' => []
        ];

        $streammanager = stream_manager::get_instance();
        if (!$streammanager) {
            $results['success'] = false;
            $results['error'] = 'Stream manager not available';
            return $results;
        }

        // Get videos to sync
        $conditions = $force ? '' : 'status != ?';
        $params = $force ? [] : [video_manager::STATUS_READY];
        
        $videos = $DB->get_records_select(
            video_manager::TABLE_VIDEOS,
            $conditions,
            $params,
            'upload_date ASC',
            '*',
            0,
            100 // Limit to avoid timeouts
        );

        foreach ($videos as $video) {
            $results['total_processed']++;
            
            try {
                $syncresult = $streammanager->sync_video_status($video->id);
                
                if ($syncresult['success']) {
                    if ($syncresult['updated']) {
                        $results['updated']++;
                        $results['details'][] = "Updated video {$video->id}: {$syncresult['old_status']} → {$syncresult['new_status']}";
                    }
                } else {
                    $results['errors']++;
                    $results['details'][] = "Failed to sync video {$video->id}: " . ($syncresult['error'] ?? 'Unknown error');
                }
            } catch (\Exception $e) {
                $results['errors']++;
                $results['details'][] = "Exception syncing video {$video->id}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Sync specific video by ID.
     *
     * @param int $videoid Video ID
     * @return array Sync result
     */
    public static function sync_video($videoid) {
        $streammanager = stream_manager::get_instance();
        if (!$streammanager) {
            return [
                'success' => false,
                'error' => 'Stream manager not available'
            ];
        }

        return $streammanager->sync_video_status($videoid);
    }

    /**
     * Sync videos by status.
     *
     * @param string $status Video status to sync
     * @param int $limit Maximum number of videos to sync
     * @return array Sync results
     */
    public static function sync_videos_by_status($status, $limit = 50) {
        $videos = video_manager::get_videos_by_status($status, $limit);
        
        $results = [
            'success' => true,
            'total_processed' => count($videos),
            'updated' => 0,
            'errors' => 0,
            'details' => []
        ];

        $streammanager = stream_manager::get_instance();
        if (!$streammanager) {
            $results['success'] = false;
            $results['error'] = 'Stream manager not available';
            return $results;
        }

        foreach ($videos as $video) {
            try {
                $syncresult = $streammanager->sync_video_status($video->id);
                
                if ($syncresult['success'] && $syncresult['updated']) {
                    $results['updated']++;
                    $results['details'][] = "Updated video {$video->id}: {$syncresult['old_status']} → {$syncresult['new_status']}";
                } elseif (!$syncresult['success']) {
                    $results['errors']++;
                    $results['details'][] = "Failed to sync video {$video->id}: " . ($syncresult['error'] ?? 'Unknown error');
                }
            } catch (\Exception $e) {
                $results['errors']++;
                $results['details'][] = "Exception syncing video {$video->id}: " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * Clean up orphaned videos.
     *
     * @param bool $dryrun If true, only report what would be cleaned
     * @return array Cleanup results
     */
    public static function cleanup_orphaned_videos($dryrun = true) {
        global $DB;

        $results = [
            'success' => true,
            'moodle_orphans_found' => 0,
            'moodle_orphans_cleaned' => 0,
            'cloudflare_orphans_found' => 0,
            'cloudflare_orphans_cleaned' => 0,
            'details' => [],
            'dry_run' => $dryrun
        ];

        $streammanager = stream_manager::get_instance();
        if (!$streammanager) {
            $results['success'] = false;
            $results['error'] = 'Stream manager not available';
            return $results;
        }

        // Find Moodle orphans (videos in Moodle that don't exist on Cloudflare)
        $moodleorphans = self::find_moodle_orphans();
        $results['moodle_orphans_found'] = count($moodleorphans);

        foreach ($moodleorphans as $videoid) {
            if (!$dryrun) {
                // Mark as error instead of deleting
                video_manager::update_video($videoid, [
                    'status' => video_manager::STATUS_ERROR,
                    'error_message' => 'Video not found on Cloudflare - marked as orphan'
                ]);
                $results['moodle_orphans_cleaned']++;
            }
            $results['details'][] = ($dryrun ? '[DRY RUN] Would mark' : 'Marked') . " Moodle video {$videoid} as orphaned";
        }

        // Find Cloudflare orphans
        $cloudflareorphans = self::find_cloudflare_orphans();
        $results['cloudflare_orphans_found'] = count($cloudflareorphans);

        foreach ($cloudflareorphans as $cfvideoid) {
            if (!$dryrun) {
                // Delete from Cloudflare (be careful with this!)
                $deleteresult = $streammanager->delete_video($cfvideoid);
                if ($deleteresult['success']) {
                    $results['cloudflare_orphans_cleaned']++;
                    $results['details'][] = "Deleted Cloudflare orphan: {$cfvideoid}";
                } else {
                    $results['details'][] = "Failed to delete Cloudflare orphan {$cfvideoid}: " . ($deleteresult['error'] ?? 'Unknown error');
                }
            } else {
                $results['details'][] = "[DRY RUN] Would delete Cloudflare orphan: {$cfvideoid}";
            }
        }

        return $results;
    }

    /**
     * Find videos in Moodle that don't exist on Cloudflare.
     *
     * @return array List of orphaned video IDs
     */
    private static function find_moodle_orphans() {
        global $DB;
        
        $streammanager = stream_manager::get_instance();
        if (!$streammanager) {
            return [];
        }

        // Get ready videos that should exist on Cloudflare
        $readyvideos = $DB->get_records_select(
            video_manager::TABLE_VIDEOS,
            'status = ? AND cloudflare_video_id IS NOT NULL',
            [video_manager::STATUS_READY],
            '',
            'id, cloudflare_video_id'
        );

        $orphans = [];
        
        foreach ($readyvideos as $video) {
            // Check if video exists on Cloudflare
            $result = $streammanager->get_video_metadata($video->cloudflare_video_id);
            
            if (!$result['success'] || empty($result['data'])) {
                $orphans[] = $video->id;
            }
        }
        
        return $orphans;
    }

    /**
     * Find videos on Cloudflare that don't exist in Moodle.
     *
     * @return array List of orphaned Cloudflare video IDs
     */
    private static function find_cloudflare_orphans() {
        global $DB;
        
        $streammanager = stream_manager::get_instance();
        if (!$streammanager) {
            return [];
        }

        // Get list of videos from Cloudflare
        $cloudflarevideos = $streammanager->list_videos(200);
        
        if (!$cloudflarevideos['success'] || empty($cloudflarevideos['data'])) {
            return [];
        }

        $orphans = [];
        
        foreach ($cloudflarevideos['data'] as $cfvideo) {
            $cfvideoid = $cfvideo['uid'];
            
            // Check if this video exists in our database
            $exists = $DB->record_exists(video_manager::TABLE_VIDEOS, ['cloudflare_video_id' => $cfvideoid]);
            
            if (!$exists) {
                $orphans[] = $cfvideoid;
            }
        }
        
        return $orphans;
    }

    /**
     * Get synchronization statistics.
     *
     * @return array Statistics
     */
    public static function get_sync_statistics() {
        global $DB;

        $stats = [];

        // Count videos by status
        $statuses = [
            video_manager::STATUS_PENDING,
            video_manager::STATUS_UPLOADING,
            video_manager::STATUS_PROCESSING,
            video_manager::STATUS_READY,
            video_manager::STATUS_ERROR
        ];

        foreach ($statuses as $status) {
            $stats['by_status'][$status] = $DB->count_records(video_manager::TABLE_VIDEOS, ['status' => $status]);
        }

        // Videos needing sync (not ready or error)
        $stats['needs_sync'] = $DB->count_records_select(
            video_manager::TABLE_VIDEOS,
            'status NOT IN (?, ?)',
            [video_manager::STATUS_READY, video_manager::STATUS_ERROR]
        );

        // Old videos that might be stuck
        $cutoff = time() - 7200; // 2 hours ago
        $stats['potentially_stuck'] = $DB->count_records_select(
            video_manager::TABLE_VIDEOS,
            'status IN (?, ?) AND upload_date < ?',
            [video_manager::STATUS_UPLOADING, video_manager::STATUS_PROCESSING, $cutoff]
        );

        // Last sync time
        $stats['last_sync'] = get_config('local_cloudflarestream', 'last_health_check') ?: 0;

        return $stats;
    }

    /**
     * Reset video status for manual retry.
     *
     * @param int $videoid Video ID
     * @return array Result
     */
    public static function reset_video_for_retry($videoid) {
        $video = video_manager::get_video($videoid);
        if (!$video) {
            return [
                'success' => false,
                'error' => 'Video not found'
            ];
        }

        // Only allow reset for error status videos
        if ($video->status !== video_manager::STATUS_ERROR) {
            return [
                'success' => false,
                'error' => 'Can only reset videos with error status'
            ];
        }

        // Reset to pending status
        $updated = video_manager::update_video($videoid, [
            'status' => video_manager::STATUS_PENDING,
            'error_message' => null,
            'retry_count' => ($video->retry_count ?? 0) + 1
        ]);

        if ($updated) {
            // Add back to queue
            video_manager::add_to_queue($videoid, 'upload', [
                'retry' => true,
                'previous_error' => $video->error_message
            ]);

            return [
                'success' => true,
                'message' => 'Video reset and added back to queue'
            ];
        }

        return [
            'success' => false,
            'error' => 'Failed to update video status'
        ];
    }
}