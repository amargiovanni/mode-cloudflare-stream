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
 * Scheduled task to sync video status and perform health checks.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream\task;

use local_cloudflarestream\video_manager;
use local_cloudflarestream\config_manager;
use local_cloudflarestream\api\stream_manager;
use local_cloudflarestream\notification_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task to sync video status with Cloudflare Stream and perform health checks.
 */
class sync_videos extends \core\task\scheduled_task {

    /**
     * Get task name.
     *
     * @return string Task name
     */
    public function get_name() {
        return get_string('task_sync_videos', 'local_cloudflarestream');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        // Check if plugin is configured
        if (!config_manager::is_configured()) {
            mtrace('Cloudflare Stream plugin is not configured. Skipping sync.');
            return;
        }

        mtrace('Starting Cloudflare Stream video sync and health check...');

        // Perform health checks first
        $healthstatus = $this->perform_health_checks();
        
        // Sync video statuses
        $syncresults = $this->sync_video_statuses();
        
        // Check for stuck videos
        $stuckresults = $this->check_stuck_videos();
        
        // Check for orphaned videos
        $orphanresults = $this->check_orphaned_videos();
        
        // Generate health report
        $this->generate_health_report($healthstatus, $syncresults, $stuckresults, $orphanresults);
        
        mtrace('Video sync and health check completed.');
    }

    /**
     * Perform comprehensive health checks.
     *
     * @return array Health check results
     */
    private function perform_health_checks() {
        mtrace('Performing health checks...');
        
        $results = [
            'api_connection' => $this->check_api_connection(),
            'database_health' => $this->check_database_health(),
            'queue_health' => $this->check_queue_health(),
            'storage_health' => $this->check_storage_health(),
            'token_health' => $this->check_token_health()
        ];
        
        // Count issues
        $issues = 0;
        foreach ($results as $check => $result) {
            if (!$result['healthy']) {
                $issues++;
                mtrace("Health check failed: {$check} - " . $result['message']);
            }
        }
        
        $results['overall_healthy'] = $issues === 0;
        $results['issues_count'] = $issues;
        
        return $results;
    }

    /**
     * Check API connection health.
     *
     * @return array Check result
     */
    private function check_api_connection() {
        try {
            $client = \local_cloudflarestream\api\cloudflare_client::get_instance();
            if (!$client) {
                return [
                    'healthy' => false,
                    'message' => 'API client not configured'
                ];
            }

            $result = $client->test_connection();
            return [
                'healthy' => $result['success'],
                'message' => $result['message']
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'message' => 'API connection error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check database health.
     *
     * @return array Check result
     */
    private function check_database_health() {
        global $DB;
        
        try {
            // Check if tables exist and are accessible
            $videocount = $DB->count_records(video_manager::TABLE_VIDEOS);
            $queuecount = $DB->count_records(video_manager::TABLE_QUEUE);
            
            // Check for database inconsistencies
            $inconsistencies = $this->find_database_inconsistencies();
            
            return [
                'healthy' => empty($inconsistencies),
                'message' => empty($inconsistencies) ? 
                    "Database healthy ({$videocount} videos, {$queuecount} queue items)" :
                    'Database inconsistencies found: ' . implode(', ', $inconsistencies),
                'video_count' => $videocount,
                'queue_count' => $queuecount,
                'inconsistencies' => $inconsistencies
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Find database inconsistencies.
     *
     * @return array List of inconsistencies
     */
    private function find_database_inconsistencies() {
        global $DB;
        
        $inconsistencies = [];
        
        // Check for videos with missing Moodle files
        $sql = "SELECT COUNT(*) FROM {" . video_manager::TABLE_VIDEOS . "} v
                LEFT JOIN {files} f ON f.id = v.moodle_file_id
                WHERE f.id IS NULL";
        $orphanedvideos = $DB->count_records_sql($sql);
        
        if ($orphanedvideos > 0) {
            $inconsistencies[] = "{$orphanedvideos} videos with missing Moodle files";
        }
        
        // Check for videos stuck in uploading state for too long
        $cutoff = time() - 3600; // 1 hour ago
        $stuckuploading = $DB->count_records_select(
            video_manager::TABLE_VIDEOS,
            'status = ? AND upload_date < ?',
            [video_manager::STATUS_UPLOADING, $cutoff]
        );
        
        if ($stuckuploading > 0) {
            $inconsistencies[] = "{$stuckuploading} videos stuck in uploading state";
        }
        
        return $inconsistencies;
    }

    /**
     * Check queue health.
     *
     * @return array Check result
     */
    private function check_queue_health() {
        global $DB;
        
        try {
            $total = $DB->count_records(video_manager::TABLE_QUEUE);
            $failed = $DB->count_records_select(video_manager::TABLE_QUEUE, 'attempts >= max_attempts');
            $old = $DB->count_records_select(video_manager::TABLE_QUEUE, 'timecreated < ?', [time() - 86400]);
            
            $issues = [];
            if ($failed > 10) {
                $issues[] = "{$failed} permanently failed items";
            }
            if ($old > 50) {
                $issues[] = "{$old} items older than 24 hours";
            }
            
            return [
                'healthy' => empty($issues),
                'message' => empty($issues) ? 
                    "Queue healthy ({$total} total, {$failed} failed)" :
                    'Queue issues: ' . implode(', ', $issues),
                'total' => $total,
                'failed' => $failed,
                'old' => $old
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'message' => 'Queue check error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check storage health.
     *
     * @return array Check result
     */
    private function check_storage_health() {
        try {
            $stats = video_manager::get_statistics();
            $totalsize = $stats['storage_used'];
            
            // Check if storage usage is reasonable
            $maxsize = config_manager::get('max_file_size', 524288000) * $stats['total'];
            $usage_ratio = $maxsize > 0 ? $totalsize / $maxsize : 0;
            
            $warnings = [];
            if ($usage_ratio > 0.8) {
                $warnings[] = 'High storage usage';
            }
            
            return [
                'healthy' => empty($warnings),
                'message' => empty($warnings) ? 
                    'Storage healthy (' . $this->format_bytes($totalsize) . ' used)' :
                    implode(', ', $warnings),
                'total_size' => $totalsize,
                'usage_ratio' => $usage_ratio
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'message' => 'Storage check error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check token health.
     *
     * @return array Check result
     */
    private function check_token_health() {
        try {
            $stats = \local_cloudflarestream\auth\token_manager::get_token_statistics(1);
            
            $warnings = [];
            if ($stats['total_active'] > 1000) {
                $warnings[] = 'High number of active tokens';
            }
            
            return [
                'healthy' => empty($warnings),
                'message' => empty($warnings) ? 
                    "Token system healthy ({$stats['total_active']} active)" :
                    implode(', ', $warnings),
                'active_tokens' => $stats['total_active']
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'message' => 'Token check error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Sync video statuses with Cloudflare.
     *
     * @return array Sync results
     */
    private function sync_video_statuses() {
        mtrace('Syncing video statuses...');
        
        $streammanager = stream_manager::get_instance();
        if (!$streammanager) {
            return [
                'success' => false,
                'error' => 'Stream manager not available'
            ];
        }

        // Get videos that need status sync
        $processingvideos = video_manager::get_videos_by_status(video_manager::STATUS_PROCESSING, 20);
        $uploadingvideos = video_manager::get_videos_by_status(video_manager::STATUS_UPLOADING, 10);
        
        $allvideos = array_merge($processingvideos, $uploadingvideos);
        
        if (empty($allvideos)) {
            mtrace('No videos need status sync.');
            return [
                'success' => true,
                'synced' => 0,
                'failed' => 0
            ];
        }

        $videoIds = array_column($allvideos, 'id');
        $results = $streammanager->bulk_sync_videos($videoIds, 5);
        
        mtrace("Synced {$results['success']} videos, {$results['failed']} failed");
        
        return $results;
    }

    /**
     * Check for stuck videos and attempt recovery.
     *
     * @return array Check results
     */
    private function check_stuck_videos() {
        mtrace('Checking for stuck videos...');
        
        global $DB;
        
        // Find videos stuck in uploading state for more than 2 hours
        $cutoff = time() - 7200; // 2 hours ago
        $stuckvideos = $DB->get_records_select(
            video_manager::TABLE_VIDEOS,
            'status IN (?, ?) AND upload_date < ?',
            [video_manager::STATUS_UPLOADING, video_manager::STATUS_PROCESSING, $cutoff],
            'upload_date ASC',
            '*',
            0,
            10
        );

        $recovered = 0;
        $failed = 0;

        foreach ($stuckvideos as $video) {
            mtrace("Attempting to recover stuck video {$video->id}");
            
            if ($this->attempt_video_recovery($video)) {
                $recovered++;
            } else {
                $failed++;
            }
        }

        if ($recovered > 0 || $failed > 0) {
            mtrace("Video recovery: {$recovered} recovered, {$failed} failed");
        }

        return [
            'stuck_found' => count($stuckvideos),
            'recovered' => $recovered,
            'failed' => $failed
        ];
    }

    /**
     * Attempt to recover a stuck video.
     *
     * @param \stdClass $video Video record
     * @return bool Success
     */
    private function attempt_video_recovery($video) {
        $streammanager = stream_manager::get_instance();
        if (!$streammanager) {
            return false;
        }

        // Try to sync status first
        $syncresult = $streammanager->sync_video_status($video->id);
        if ($syncresult['success'] && $syncresult['updated']) {
            return true;
        }

        // If sync didn't help, mark as error for manual review
        video_manager::update_video($video->id, [
            'status' => video_manager::STATUS_ERROR,
            'error_message' => 'Video stuck in processing - marked for manual review'
        ]);

        return false;
    }

    /**
     * Check for orphaned videos on both platforms.
     *
     * @return array Orphan check results
     */
    private function check_orphaned_videos() {
        mtrace('Checking for orphaned videos...');
        
        $results = [
            'moodle_orphans' => $this->find_moodle_orphans(),
            'cloudflare_orphans' => $this->find_cloudflare_orphans(),
            'total_orphans' => 0
        ];
        
        $results['total_orphans'] = count($results['moodle_orphans']) + count($results['cloudflare_orphans']);
        
        if ($results['total_orphans'] > 0) {
            mtrace("Found {$results['total_orphans']} orphaned videos");
        } else {
            mtrace('No orphaned videos found');
        }
        
        return $results;
    }

    /**
     * Find videos in Moodle that don't exist on Cloudflare.
     *
     * @return array List of orphaned video IDs
     */
    private function find_moodle_orphans() {
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
            'id, cloudflare_video_id',
            0,
            50 // Limit to avoid API rate limits
        );

        $orphans = [];
        
        foreach ($readyvideos as $video) {
            // Check if video exists on Cloudflare
            $result = $streammanager->get_video_metadata($video->cloudflare_video_id);
            
            if (!$result['success'] || empty($result['data'])) {
                $orphans[] = $video->id;
                mtrace("Moodle orphan found: video {$video->id} (Cloudflare ID: {$video->cloudflare_video_id})");
                
                // Mark as error for manual review
                video_manager::update_video($video->id, [
                    'status' => video_manager::STATUS_ERROR,
                    'error_message' => 'Video not found on Cloudflare - possible orphan'
                ]);
            }
        }
        
        return $orphans;
    }

    /**
     * Find videos on Cloudflare that don't exist in Moodle.
     *
     * @return array List of orphaned Cloudflare video IDs
     */
    private function find_cloudflare_orphans() {
        global $DB;
        
        $streammanager = stream_manager::get_instance();
        if (!$streammanager) {
            return [];
        }

        // Get list of videos from Cloudflare
        $cloudflarevideos = $streammanager->list_videos(100); // Limit to avoid large responses
        
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
                mtrace("Cloudflare orphan found: {$cfvideoid}");
                
                // Log orphaned video for potential cleanup
                $this->log_cloudflare_orphan($cfvideo);
            }
        }
        
        return $orphans;
    }

    /**
     * Log orphaned Cloudflare video for review.
     *
     * @param array $video Cloudflare video data
     */
    private function log_cloudflare_orphan($video) {
        // Store orphan info in config for admin review
        $orphans = json_decode(get_config('local_cloudflarestream', 'cloudflare_orphans') ?: '[]', true);
        
        $orphans[$video['uid']] = [
            'id' => $video['uid'],
            'filename' => $video['meta']['name'] ?? 'Unknown',
            'size' => $video['size'] ?? 0,
            'created' => $video['created'] ?? '',
            'found_at' => time()
        ];
        
        // Keep only recent orphans (last 30 days)
        $cutoff = time() - (30 * 24 * 3600);
        $orphans = array_filter($orphans, function($orphan) use ($cutoff) {
            return $orphan['found_at'] > $cutoff;
        });
        
        set_config('cloudflare_orphans', json_encode($orphans), 'local_cloudflarestream');
    }

    /**
     * Generate and store health report.
     *
     * @param array $healthstatus Health check results
     * @param array $syncresults Sync results
     * @param array $stuckresults Stuck video results
     * @param array $orphanresults Orphan check results
     */
    private function generate_health_report($healthstatus, $syncresults, $stuckresults, $orphanresults = []) {
        $report = [
            'timestamp' => time(),
            'overall_healthy' => $healthstatus['overall_healthy'] && $orphanresults['total_orphans'] == 0,
            'issues_count' => $healthstatus['issues_count'] + ($orphanresults['total_orphans'] > 0 ? 1 : 0),
            'health_checks' => $healthstatus,
            'sync_results' => $syncresults,
            'stuck_results' => $stuckresults,
            'orphan_results' => $orphanresults
        ];

        // Store report
        set_config('last_health_report', json_encode($report), 'local_cloudflarestream');
        set_config('last_health_check', time(), 'local_cloudflarestream');

        // Send alerts if needed
        if (!$report['overall_healthy'] || $stuckresults['failed'] > 0 || $orphanresults['total_orphans'] > 0) {
            $this->send_health_alert($report);
        }

        mtrace('Health report generated and stored.');
    }

    /**
     * Send health alert to administrators.
     *
     * @param array $report Health report
     */
    private function send_health_alert($report) {
        $subject = 'Cloudflare Stream Health Alert';
        $message = $this->format_health_alert_message($report);
        
        notification_manager::notify_admin($subject, $message);
        
        mtrace('Health alert sent to administrators.');
    }

    /**
     * Format health alert message.
     *
     * @param array $report Health report
     * @return string Formatted message
     */
    private function format_health_alert_message($report) {
        $message = "Cloudflare Stream Health Alert\n";
        $message .= "Time: " . userdate($report['timestamp']) . "\n\n";
        
        if (!$report['overall_healthy']) {
            $message .= "Health Issues Found ({$report['issues_count']} issues):\n";
            foreach ($report['health_checks'] as $check => $result) {
                if (!$result['healthy']) {
                    $message .= "- {$check}: {$result['message']}\n";
                }
            }
            $message .= "\n";
        }

        if ($report['stuck_results']['failed'] > 0) {
            $message .= "Video Recovery Issues:\n";
            $message .= "- {$report['stuck_results']['failed']} videos could not be recovered\n";
            $message .= "- Manual intervention may be required\n\n";
        }

        $message .= "Please check the Cloudflare Stream dashboard for more details.\n";
        
        return $message;
    }

    /**
     * Format bytes to human readable format.
     *
     * @param int $bytes Number of bytes
     * @return string Formatted string
     */
    private function format_bytes($bytes) {
        if ($bytes == 0) {
            return '0 B';
        }
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor(log($bytes, 1024));
        
        return sprintf('%.1f %s', $bytes / pow(1024, $factor), $units[$factor]);
    }
}