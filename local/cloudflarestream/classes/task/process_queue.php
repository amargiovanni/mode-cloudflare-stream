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
 * Scheduled task to process upload queue.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream\task;

use local_cloudflarestream\video_manager;
use local_cloudflarestream\handlers\upload_handler;
use local_cloudflarestream\config_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task to process video upload queue.
 */
class process_queue extends \core\task\scheduled_task {

    /**
     * Get task name.
     *
     * @return string Task name
     */
    public function get_name() {
        return get_string('task_process_queue', 'local_cloudflarestream');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        // Check if plugin is configured
        if (!config_manager::is_configured()) {
            mtrace('Cloudflare Stream plugin is not configured. Skipping queue processing.');
            return;
        }

        mtrace('Starting Cloudflare Stream queue processing...');

        $processed = 0;
        $failed = 0;
        $batchsize = 5; // Process 5 items per run to avoid timeouts

        // Get queue items to process
        $queueitems = video_manager::get_queue_items($batchsize);

        if (empty($queueitems)) {
            mtrace('No queue items to process.');
            return;
        }

        mtrace('Found ' . count($queueitems) . ' queue items to process.');

        foreach ($queueitems as $item) {
            try {
                mtrace("Processing queue item {$item->id} (action: {$item->action}, video: {$item->video_id})");

                $result = $this->process_queue_item($item);

                if ($result['success']) {
                    // Remove successful item from queue
                    video_manager::remove_queue_item($item->id);
                    $processed++;
                    mtrace("Successfully processed queue item {$item->id}");
                    
                    // Send success notification for uploads
                    if ($item->action === 'upload') {
                        $this->send_upload_notification($item->video_id, true);
                    }
                } else {
                    // Update failed item
                    $this->handle_failed_item($item, $result['error'], $result['retry']);
                    $failed++;
                    mtrace("Failed to process queue item {$item->id}: " . $result['error']);
                    
                    // Send failure notification for uploads if max attempts reached
                    if ($item->action === 'upload' && ($item->attempts + 1) >= $item->max_attempts) {
                        $this->send_upload_notification($item->video_id, false, $result['error']);
                    }
                }

            } catch (\Exception $e) {
                // Handle unexpected errors
                $this->handle_failed_item($item, $e->getMessage(), true);
                $failed++;
                mtrace("Exception processing queue item {$item->id}: " . $e->getMessage());
            }
        }

        mtrace("Queue processing completed. Processed: {$processed}, Failed: {$failed}");

        // Clean up old failed items
        $cleaned = video_manager::cleanup_queue();
        if ($cleaned > 0) {
            mtrace("Cleaned up {$cleaned} old queue items.");
        }
    }

    /**
     * Process individual queue item.
     *
     * @param \stdClass $item Queue item
     * @return array Processing result
     */
    private function process_queue_item($item) {
        switch ($item->action) {
            case 'upload':
                return upload_handler::process_upload_queue_item($item);
            
            case 'delete':
                return $this->process_delete_item($item);
            
            case 'sync':
                return $this->process_sync_item($item);
            
            default:
                return [
                    'success' => false,
                    'error' => 'Unknown action: ' . $item->action,
                    'retry' => false
                ];
        }
    }

    /**
     * Process delete queue item.
     *
     * @param \stdClass $item Queue item
     * @return array Processing result
     */
    private function process_delete_item($item) {
        try {
            $streammanager = \local_cloudflarestream\api\stream_manager::get_instance();
            if (!$streammanager) {
                return [
                    'success' => false,
                    'error' => 'Stream manager not available',
                    'retry' => true
                ];
            }

            $result = $streammanager->delete_video($item->video_id);
            
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
     * Process sync queue item.
     *
     * @param \stdClass $item Queue item
     * @return array Processing result
     */
    private function process_sync_item($item) {
        try {
            $streammanager = \local_cloudflarestream\api\stream_manager::get_instance();
            if (!$streammanager) {
                return [
                    'success' => false,
                    'error' => 'Stream manager not available',
                    'retry' => true
                ];
            }

            $result = $streammanager->sync_video_status($item->video_id);
            
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
     * Handle failed queue item.
     *
     * @param \stdClass $item Queue item
     * @param string $error Error message
     * @param bool $retry Whether to retry
     */
    private function handle_failed_item($item, $error, $retry) {
        $attempts = $item->attempts + 1;
        
        if (!$retry || $attempts >= $item->max_attempts) {
            // Max attempts reached or not retryable - mark as failed
            $errorlog = $item->error_log ? $item->error_log . "\n" : '';
            $errorlog .= date('Y-m-d H:i:s') . ': ' . $error;
            
            video_manager::update_queue_item($item->id, [
                'attempts' => $attempts,
                'error_log' => $errorlog,
                'next_attempt' => time() + 86400 // Try again in 24 hours
            ]);

            // Also update video status if it's an upload
            if ($item->action === 'upload') {
                video_manager::update_video($item->video_id, [
                    'status' => video_manager::STATUS_ERROR,
                    'error_message' => $error
                ]);
            }
        } else {
            // Schedule retry with exponential backoff
            $delay = min(pow(2, $attempts) * 60, 3600); // Max 1 hour delay
            $nextAttempt = time() + $delay;
            
            $errorlog = $item->error_log ? $item->error_log . "\n" : '';
            $errorlog .= date('Y-m-d H:i:s') . ': Attempt ' . $attempts . ' failed: ' . $error;
            
            video_manager::update_queue_item($item->id, [
                'attempts' => $attempts,
                'next_attempt' => $nextAttempt,
                'error_log' => $errorlog
            ]);
        }
    }

    /**
     * Send upload notification to user.
     *
     * @param int $videoid Video record ID
     * @param bool $success Whether upload was successful
     * @param string $error Error message if failed
     */
    private function send_upload_notification($videoid, $success, $error = '') {
        try {
            $video = video_manager::get_video($videoid);
            if (!$video) {
                return;
            }

            if ($success) {
                \local_cloudflarestream\notification_manager::notify_upload_completed($videoid, $video->user_id);
            } else {
                \local_cloudflarestream\notification_manager::notify_upload_failed($videoid, $video->user_id, $error);
            }
        } catch (\Exception $e) {
            mtrace("Failed to send notification for video {$videoid}: " . $e->getMessage());
        }
    }
}