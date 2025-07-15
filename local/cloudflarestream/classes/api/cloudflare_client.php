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
 * Cloudflare Stream API client.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream\api;

defined('MOODLE_INTERNAL') || die();

/**
 * Cloudflare Stream API client class.
 */
class cloudflare_client {

    /** @var string API base URL */
    const API_BASE_URL = 'https://api.cloudflare.com/client/v4';

    /** @var string API token */
    private $apitoken;

    /** @var string Account ID */
    private $accountid;

    /** @var string Zone ID (optional) */
    private $zoneid;

    /** @var int Maximum retry attempts */
    private $maxretries;

    /** @var array Default cURL options */
    private $curloptions;

    /**
     * Constructor.
     *
     * @param string $apitoken API token
     * @param string $accountid Account ID
     * @param string $zoneid Zone ID (optional)
     * @param int $maxretries Maximum retry attempts
     */
    public function __construct($apitoken, $accountid, $zoneid = null, $maxretries = 3) {
        $this->apitoken = $apitoken;
        $this->accountid = $accountid;
        $this->zoneid = $zoneid;
        $this->maxretries = $maxretries;

        $this->curloptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 300, // 5 minutes for uploads
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Moodle-CloudflareStream/1.0',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apitoken,
                'Content-Type: application/json',
            ],
        ];
    }

    /**
     * Test API connection.
     *
     * @return array Result with success status and message
     */
    public function test_connection() {
        try {
            $response = $this->make_request('GET', "/accounts/{$this->accountid}/stream");
            
            if ($response['success']) {
                return [
                    'success' => true,
                    'message' => get_string('success_api_connection', 'local_cloudflarestream')
                ];
            } else {
                return [
                    'success' => false,
                    'message' => $this->format_error_message($response)
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => get_string('error_api_connection', 'local_cloudflarestream') . ': ' . $e->getMessage()
            ];
        }
    }

    /**
     * Upload video to Cloudflare Stream.
     *
     * @param string $filepath Path to video file
     * @param array $metadata Video metadata
     * @return array API response
     */
    public function upload_video($filepath, $metadata = []) {
        if (!file_exists($filepath)) {
            throw new \InvalidArgumentException('Video file not found: ' . $filepath);
        }

        $filesize = filesize($filepath);
        if ($filesize === false || $filesize === 0) {
            throw new \InvalidArgumentException('Invalid video file size');
        }

        // Prepare upload data
        $postdata = [
            'file' => new \CURLFile($filepath, mime_content_type($filepath), basename($filepath))
        ];

        // Add metadata if provided
        if (!empty($metadata)) {
            if (isset($metadata['name'])) {
                $postdata['meta[name]'] = $metadata['name'];
            }
            if (isset($metadata['requireSignedURLs'])) {
                $postdata['requireSignedURLs'] = $metadata['requireSignedURLs'] ? 'true' : 'false';
            }
            if (isset($metadata['allowedOrigins'])) {
                $postdata['allowedOrigins'] = json_encode($metadata['allowedOrigins']);
            }
        }

        // Use multipart form data for file upload
        $curloptions = $this->curloptions;
        $curloptions[CURLOPT_HTTPHEADER] = [
            'Authorization: Bearer ' . $this->apitoken,
            // Don't set Content-Type for multipart uploads - let cURL handle it
        ];
        $curloptions[CURLOPT_POSTFIELDS] = $postdata;

        return $this->make_request('POST', "/accounts/{$this->accountid}/stream", null, $curloptions);
    }

    /**
     * Get video status and details.
     *
     * @param string $videoid Cloudflare video ID
     * @return array API response
     */
    public function get_video_status($videoid) {
        return $this->make_request('GET', "/accounts/{$this->accountid}/stream/{$videoid}");
    }

    /**
     * Delete video from Cloudflare Stream.
     *
     * @param string $videoid Cloudflare video ID
     * @return array API response
     */
    public function delete_video($videoid) {
        return $this->make_request('DELETE', "/accounts/{$this->accountid}/stream/{$videoid}");
    }

    /**
     * Generate signed URL for video access.
     *
     * @param string $videoid Cloudflare video ID
     * @param int $expiry Expiration timestamp
     * @param array $options Additional options
     * @return array API response
     */
    public function generate_signed_url($videoid, $expiry, $options = []) {
        $data = [
            'exp' => $expiry,
        ];

        // Add optional parameters
        if (isset($options['downloadable'])) {
            $data['downloadable'] = $options['downloadable'];
        }
        if (isset($options['pem'])) {
            $data['pem'] = $options['pem'];
        }

        return $this->make_request('POST', "/accounts/{$this->accountid}/stream/{$videoid}/token", $data);
    }

    /**
     * List videos with optional filters.
     *
     * @param array $filters Optional filters (status, search, etc.)
     * @param int $page Page number
     * @param int $perpage Items per page
     * @return array API response
     */
    public function list_videos($filters = [], $page = 1, $perpage = 50) {
        $params = [
            'page' => $page,
            'per_page' => min($perpage, 1000), // API limit
        ];

        // Add filters
        if (isset($filters['status'])) {
            $params['status'] = $filters['status'];
        }
        if (isset($filters['search'])) {
            $params['search'] = $filters['search'];
        }
        if (isset($filters['creator'])) {
            $params['creator'] = $filters['creator'];
        }

        $querystring = http_build_query($params);
        return $this->make_request('GET', "/accounts/{$this->accountid}/stream?" . $querystring);
    }

    /**
     * Update video metadata.
     *
     * @param string $videoid Cloudflare video ID
     * @param array $metadata New metadata
     * @return array API response
     */
    public function update_video_metadata($videoid, $metadata) {
        $data = [];
        
        if (isset($metadata['name'])) {
            $data['meta'] = ['name' => $metadata['name']];
        }
        if (isset($metadata['requireSignedURLs'])) {
            $data['requireSignedURLs'] = $metadata['requireSignedURLs'];
        }
        if (isset($metadata['allowedOrigins'])) {
            $data['allowedOrigins'] = $metadata['allowedOrigins'];
        }

        return $this->make_request('POST', "/accounts/{$this->accountid}/stream/{$videoid}", $data);
    }

    /**
     * Get account usage statistics.
     *
     * @return array API response
     */
    public function get_usage_statistics() {
        return $this->make_request('GET', "/accounts/{$this->accountid}/stream/analytics/views");
    }

    /**
     * Make HTTP request to Cloudflare API.
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @param array $customcurloptions Custom cURL options
     * @return array API response
     * @throws \Exception On request failure
     */
    private function make_request($method, $endpoint, $data = null, $customcurloptions = null) {
        $url = self::API_BASE_URL . $endpoint;
        $attempt = 0;
        $lasterror = null;

        while ($attempt < $this->maxretries) {
            $attempt++;

            try {
                $curl = curl_init();
                $curloptions = $customcurloptions ?: $this->curloptions;

                // Set basic options
                curl_setopt_array($curl, $curloptions);
                curl_setopt($curl, CURLOPT_URL, $url);
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);

                // Add request data for POST/PUT/PATCH
                if ($data !== null && !isset($curloptions[CURLOPT_POSTFIELDS])) {
                    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
                }

                $response = curl_exec($curl);
                $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                $error = curl_error($curl);
                curl_close($curl);

                if ($response === false) {
                    throw new \Exception('cURL error: ' . $error);
                }

                $decoded = json_decode($response, true);
                if ($decoded === null) {
                    throw new \Exception('Invalid JSON response: ' . $response);
                }

                // Handle HTTP errors
                if ($httpcode >= 400) {
                    // Check if this is a retryable error
                    if ($httpcode >= 500 || $httpcode === 429) {
                        $lasterror = new \Exception("HTTP {$httpcode}: " . $this->format_error_message($decoded));
                        $this->wait_before_retry($attempt);
                        continue;
                    } else {
                        // Client error - don't retry
                        throw new \Exception("HTTP {$httpcode}: " . $this->format_error_message($decoded));
                    }
                }

                // Log successful request
                $this->log_request($method, $endpoint, $httpcode, $attempt);

                return $decoded;

            } catch (\Exception $e) {
                $lasterror = $e;
                if ($attempt < $this->maxretries) {
                    $this->wait_before_retry($attempt);
                }
            }
        }

        // All attempts failed
        throw new \Exception("API request failed after {$this->maxretries} attempts: " . $lasterror->getMessage());
    }

    /**
     * Wait before retry with exponential backoff.
     *
     * @param int $attempt Attempt number
     */
    private function wait_before_retry($attempt) {
        $delay = min(pow(2, $attempt - 1), 30); // Max 30 seconds
        sleep($delay);
    }

    /**
     * Format error message from API response.
     *
     * @param array $response API response
     * @return string Formatted error message
     */
    private function format_error_message($response) {
        if (isset($response['errors']) && is_array($response['errors'])) {
            $messages = [];
            foreach ($response['errors'] as $error) {
                if (isset($error['message'])) {
                    $messages[] = $error['message'];
                }
            }
            return implode('; ', $messages);
        }

        if (isset($response['message'])) {
            return $response['message'];
        }

        return 'Unknown API error';
    }

    /**
     * Log API request for debugging.
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param int $httpcode HTTP response code
     * @param int $attempt Attempt number
     */
    private function log_request($method, $endpoint, $httpcode, $attempt) {
        if (debugging('', DEBUG_DEVELOPER)) {
            $message = "Cloudflare API: {$method} {$endpoint} -> HTTP {$httpcode}";
            if ($attempt > 1) {
                $message .= " (attempt {$attempt})";
            }
            debugging($message, DEBUG_DEVELOPER);
        }
    }

    /**
     * Get API configuration from plugin settings.
     *
     * @return self|null Client instance or null if not configured
     */
    public static function get_instance() {
        $apitoken = get_config('local_cloudflarestream', 'api_token');
        $accountid = get_config('local_cloudflarestream', 'account_id');
        $zoneid = get_config('local_cloudflarestream', 'zone_id');

        if (empty($apitoken) || empty($accountid)) {
            return null;
        }

        return new self($apitoken, $accountid, $zoneid);
    }
}