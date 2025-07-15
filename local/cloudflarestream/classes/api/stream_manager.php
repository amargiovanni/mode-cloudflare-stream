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
 * Stream manager for video upload operations.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream\api;

use local_cloudflarestream\video_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages video upload operations to Cloudflare Stream.
 */
class stream_manager {

    /** @var cloudflare_client API client */
    private $client;

    /** @var array Supported video formats */
    private $supportedformats;

    /** @var int Maximum file size */
    private $maxfilesize;

    /**
     * Constructor.
     *
     * @param cloudflare_client $client API client
     */
    public function __construct(cloudflare_client $client) {
        $this->client = $client;
        $this->load_configuration();
    }

    /**
     * Load configuration from plugin settings.
     */
    private function load_configuration() {
        $formats = get_config('local_cloudflarestream', 'supported_formats');
        $this->supportedformats = array_map('trim', explode(',', strtolower($formats ?: 'mp4,mov,avi,mkv,webm')));
        
        $this->maxfilesize = (int)get_config('local_cloudflarestream', 'max_file_size') ?: 524288000; // 500MB default
    }

    /**
     * Upload video file to Cloudflare Stream.
     *
     * @param string $filepath Path to video file
     * @param int $videoid Video record ID
     * @param array $metadata Additional metadata
     * @param callable $progresscallback Progress callback function
     * @return array Upload result
     */
    public function upload_video($filepath, $videoid, $metadata = [], $progresscallback = null) {
        try {
            // Validate file
            $validation = $this->validate_video_file($filepath);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'error' => $validation['error']
                ];
            }

            // Update video status to uploading
            video_manager::update_video($videoid, [
                'status' => video_manager::STATUS_UPLOADING
            ]);

            // Prepare metadata
            $uploadmetadata = $this->prepare_upload_metadata($metadata);

            // Call progress callback if provided
            if ($progresscallback) {
                call_user_func($progresscallback, 'starting', 0);
            }

            // Upload to Cloudflare
            $response = $this->client->upload_video($filepath, $uploadmetadata);

            if ($progresscallback) {
                call_user_func($progresscallback, 'uploaded', 100);
            }

            if (!$response['success']) {
                // Upload failed
                video_manager::update_video($videoid, [
                    'status' => video_manager::STATUS_ERROR,
                    'error_message' => $this->format_api_error($response)
                ]);

                return [
                    'success' => false,
                    'error' => $this->format_api_error($response)
                ];
            }

            // Upload successful - update video record
            $cloudflaredata = $response['result'];
            $updatedata = [
                'cloudflare_video_id' => $cloudflaredata['uid'],
                'status' => $this->map_cloudflare_status($cloudflaredata['status']['state']),
                'processing_date' => time(),
                'metadata' => json_encode($cloudflaredata)
            ];

            // Add duration and thumbnail if available
            if (isset($cloudflaredata['duration'])) {
                $updatedata['duration'] = (int)$cloudflaredata['duration'];
            }
            if (isset($cloudflaredata['thumbnail'])) {
                $updatedata['thumbnail_url'] = $cloudflaredata['thumbnail'];
            }

            video_manager::update_video($videoid, $updatedata);

            return [
                'success' => true,
                'cloudflare_video_id' => $cloudflaredata['uid'],
                'status' => $updatedata['status'],
                'data' => $cloudflaredata
            ];

        } catch (\Exception $e) {
            // Handle upload exception
            video_manager::update_video($videoid, [
                'status' => video_manager::STATUS_ERROR,
                'error_message' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Upload video with progress tracking using chunked upload.
     *
     * @param string $filepath Path to video file
     * @param int $videoid Video record ID
     * @param array $metadata Additional metadata
     * @param callable $progresscallback Progress callback function
     * @return array Upload result
     */
    public function upload_video_chunked($filepath, $videoid, $metadata = [], $progresscallback = null) {
        try {
            // For now, use direct upload - chunked upload can be implemented later
            // when Cloudflare Stream supports resumable uploads
            return $this->upload_video($filepath, $videoid, $metadata, $progresscallback);
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Validate video file before upload.
     *
     * @param string $filepath Path to video file
     * @return array Validation result
     */
    public function validate_video_file($filepath) {
        // Check if file exists
        if (!file_exists($filepath)) {
            return [
                'valid' => false,
                'error' => get_string('error_video_not_found', 'local_cloudflarestream')
            ];
        }

        // Check file size
        $filesize = filesize($filepath);
        if ($filesize === false) {
            return [
                'valid' => false,
                'error' => 'Unable to determine file size'
            ];
        }

        if ($filesize > $this->maxfilesize) {
            return [
                'valid' => false,
                'error' => get_string('error_file_too_large', 'local_cloudflarestream')
            ];
        }

        if ($filesize === 0) {
            return [
                'valid' => false,
                'error' => 'File is empty'
            ];
        }

        // Check file extension
        $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        if (!in_array($extension, $this->supportedformats)) {
            return [
                'valid' => false,
                'error' => get_string('error_unsupported_format', 'local_cloudflarestream')
            ];
        }

        // Basic MIME type check
        $mimetype = mime_content_type($filepath);
        if ($mimetype && !str_starts_with($mimetype, 'video/')) {
            return [
                'valid' => false,
                'error' => 'File does not appear to be a video'
            ];
        }

        return [
            'valid' => true,
            'filesize' => $filesize,
            'extension' => $extension,
            'mimetype' => $mimetype
        ];
    }

    /**
     * Prepare metadata for upload.
     *
     * @param array $metadata Input metadata
     * @return array Formatted metadata for API
     */
    private function prepare_upload_metadata($metadata) {
        $uploadmetadata = [];

        // Set video name
        if (isset($metadata['name'])) {
            $uploadmetadata['name'] = $metadata['name'];
        }

        // Require signed URLs for security
        $uploadmetadata['requireSignedURLs'] = true;

        // Set allowed origins if configured
        if (isset($metadata['allowedOrigins'])) {
            $uploadmetadata['allowedOrigins'] = $metadata['allowedOrigins'];
        }

        return $uploadmetadata;
    }

    /**
     * Map Cloudflare status to internal status.
     *
     * @param string $cloudflareStatus Cloudflare status
     * @return string Internal status
     */
    private function map_cloudflare_status($cloudflareStatus) {
        switch (strtolower($cloudflareStatus)) {
            case 'pendingupload':
            case 'uploading':
                return video_manager::STATUS_UPLOADING;
            case 'processing':
            case 'queued':
                return video_manager::STATUS_PROCESSING;
            case 'ready':
                return video_manager::STATUS_READY;
            case 'error':
                return video_manager::STATUS_ERROR;
            default:
                return video_manager::STATUS_PROCESSING;
        }
    }

    /**
     * Format API error message.
     *
     * @param array $response API response
     * @return string Error message
     */
    private function format_api_error($response) {
        if (isset($response['errors']) && is_array($response['errors'])) {
            $messages = [];
            foreach ($response['errors'] as $error) {
                if (isset($error['message'])) {
                    $messages[] = $error['message'];
                }
            }
            return implode('; ', $messages);
        }

        return $response['message'] ?? 'Unknown upload error';
    }

    /**
     * Get upload progress for a video.
     *
     * @param int $videoid Video record ID
     * @return array Progress information
     */
    public function get_upload_progress($videoid) {
        $video = video_manager::get_video($videoid);
        if (!$video) {
            return [
                'status' => 'not_found',
                'progress' => 0
            ];
        }

        $progress = 0;
        switch ($video->status) {
            case video_manager::STATUS_PENDING:
                $progress = 0;
                break;
            case video_manager::STATUS_UPLOADING:
                $progress = 25;
                break;
            case video_manager::STATUS_PROCESSING:
                $progress = 50;
                break;
            case video_manager::STATUS_READY:
                $progress = 100;
                break;
            case video_manager::STATUS_ERROR:
                $progress = 0;
                break;
        }

        return [
            'status' => $video->status,
            'progress' => $progress,
            'error_message' => $video->error_message
        ];
    }

    /**
     * Retry failed upload.
     *
     * @param int $videoid Video record ID
     * @param string $filepath Path to video file
     * @return array Retry result
     */
    public function retry_upload($videoid, $filepath) {
        $video = video_manager::get_video($videoid);
        if (!$video) {
            return [
                'success' => false,
                'error' => 'Video record not found'
            ];
        }

        // Reset video status
        video_manager::update_video($videoid, [
            'status' => video_manager::STATUS_PENDING,
            'error_message' => null,
            'cloudflare_video_id' => null
        ]);

        // Attempt upload again
        $metadata = json_decode($video->metadata ?: '{}', true);
        return $this->upload_video($filepath, $videoid, $metadata);
    }

    /**
     * Sync video status with Cloudflare Stream.
     *
     * @param int $videoid Video record ID
     * @return array Sync result
     */
    public function sync_video_status($videoid) {
        try {
            $video = video_manager::get_video($videoid);
            if (!$video || !$video->cloudflare_video_id) {
                return [
                    'success' => false,
                    'error' => 'Video not found or no Cloudflare ID'
                ];
            }

            // Get current status from Cloudflare
            $response = $this->client->get_video_status($video->cloudflare_video_id);
            
            if (!$response['success']) {
                return [
                    'success' => false,
                    'error' => $this->format_api_error($response)
                ];
            }

            $cloudflaredata = $response['result'];
            $newstatus = $this->map_cloudflare_status($cloudflaredata['status']['state']);

            // Update local record if status changed
            $updatedata = [];
            if ($video->status !== $newstatus) {
                $updatedata['status'] = $newstatus;
                
                if ($newstatus === video_manager::STATUS_READY && !$video->ready_date) {
                    $updatedata['ready_date'] = time();
                }
            }

            // Update duration and thumbnail if available and not set
            if (isset($cloudflaredata['duration']) && !$video->duration) {
                $updatedata['duration'] = (int)$cloudflaredata['duration'];
            }
            if (isset($cloudflaredata['thumbnail']) && !$video->thumbnail_url) {
                $updatedata['thumbnail_url'] = $cloudflaredata['thumbnail'];
            }

            // Update metadata
            $updatedata['metadata'] = json_encode($cloudflaredata);

            if (!empty($updatedata)) {
                video_manager::update_video($videoid, $updatedata);
            }

            return [
                'success' => true,
                'status' => $newstatus,
                'updated' => !empty($updatedata)
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Delete video from Cloudflare Stream.
     *
     * @param int $videoid Video record ID
     * @return array Delete result
     */
    public function delete_video($videoid) {
        try {
            $video = video_manager::get_video($videoid);
            if (!$video) {
                return [
                    'success' => false,
                    'error' => 'Video record not found'
                ];
            }

            // Delete from Cloudflare if video ID exists
            if ($video->cloudflare_video_id) {
                $response = $this->client->delete_video($video->cloudflare_video_id);
                
                if (!$response['success']) {
                    // Log error but continue with local deletion
                    debugging('Failed to delete video from Cloudflare: ' . $this->format_api_error($response));
                }
            }

            // Delete local record
            video_manager::delete_video($videoid);

            return [
                'success' => true,
                'message' => 'Video deleted successfully'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get video metadata from Cloudflare.
     *
     * @param string $cloudflarevideoid Cloudflare video ID
     * @return array Video metadata
     */
    public function get_video_metadata($cloudflarevideoid) {
        try {
            $response = $this->client->get_video_status($cloudflarevideoid);
            
            if (!$response['success']) {
                return [
                    'success' => false,
                    'error' => $this->format_api_error($response)
                ];
            }

            return [
                'success' => true,
                'data' => $response['result']
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Update video metadata on Cloudflare.
     *
     * @param int $videoid Video record ID
     * @param array $metadata New metadata
     * @return array Update result
     */
    public function update_video_metadata($videoid, $metadata) {
        try {
            $video = video_manager::get_video($videoid);
            if (!$video || !$video->cloudflare_video_id) {
                return [
                    'success' => false,
                    'error' => 'Video not found or no Cloudflare ID'
                ];
            }

            $response = $this->client->update_video_metadata($video->cloudflare_video_id, $metadata);
            
            if (!$response['success']) {
                return [
                    'success' => false,
                    'error' => $this->format_api_error($response)
                ];
            }

            // Update local metadata
            $currentmetadata = json_decode($video->metadata ?: '{}', true);
            $updatedmetadata = array_merge($currentmetadata, $metadata);
            
            video_manager::update_video($videoid, [
                'metadata' => json_encode($updatedmetadata)
            ]);

            return [
                'success' => true,
                'data' => $response['result']
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Bulk sync video statuses.
     *
     * @param array $videoIds Array of video record IDs
     * @param int $batchsize Number of videos to process per batch
     * @return array Sync results
     */
    public function bulk_sync_videos($videoIds, $batchsize = 10) {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        $batches = array_chunk($videoIds, $batchsize);
        
        foreach ($batches as $batch) {
            foreach ($batch as $videoid) {
                $result = $this->sync_video_status($videoid);
                
                if ($result['success']) {
                    $results['success']++;
                } else {
                    $results['failed']++;
                    $results['errors'][$videoid] = $result['error'];
                }
            }
            
            // Small delay between batches to avoid rate limiting
            if (count($batches) > 1) {
                usleep(500000); // 0.5 seconds
            }
        }

        return $results;
    }

    /**
     * Find and sync orphaned videos.
     *
     * @return array Sync results
     */
    public function sync_orphaned_videos() {
        // Get videos that are stuck in processing states
        $stuckvideos = video_manager::get_videos_by_status(video_manager::STATUS_PROCESSING);
        $uploadingvideos = video_manager::get_videos_by_status(video_manager::STATUS_UPLOADING);
        
        $allstuck = array_merge($stuckvideos, $uploadingvideos);
        $videoIds = array_column($allstuck, 'id');

        if (empty($videoIds)) {
            return [
                'success' => 0,
                'failed' => 0,
                'message' => 'No stuck videos found'
            ];
        }

        return $this->bulk_sync_videos($videoIds);
    }

    /**
     * Get comprehensive error handling for API operations.
     *
     * @param array $response API response
     * @param string $operation Operation name
     * @return array Formatted error information
     */
    public function handle_api_error($response, $operation) {
        $error = [
            'operation' => $operation,
            'timestamp' => time(),
            'message' => $this->format_api_error($response)
        ];

        // Add specific error codes if available
        if (isset($response['errors']) && is_array($response['errors'])) {
            $error['codes'] = [];
            foreach ($response['errors'] as $apierror) {
                if (isset($apierror['code'])) {
                    $error['codes'][] = $apierror['code'];
                }
            }
        }

        // Determine if error is retryable
        $error['retryable'] = $this->is_retryable_error($response);

        return $error;
    }

    /**
     * Check if an API error is retryable.
     *
     * @param array $response API response
     * @return bool True if retryable
     */
    private function is_retryable_error($response) {
        // Check for specific error codes that indicate temporary issues
        if (isset($response['errors']) && is_array($response['errors'])) {
            foreach ($response['errors'] as $error) {
                if (isset($error['code'])) {
                    // Rate limiting, server errors, etc.
                    if (in_array($error['code'], [10000, 10001, 10002, 10013])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Get instance with default client.
     *
     * @return self|null Stream manager instance or null if not configured
     */
    public static function get_instance() {
        $client = cloudflare_client::get_instance();
        if (!$client) {
            return null;
        }

        return new self($client);
    }
}