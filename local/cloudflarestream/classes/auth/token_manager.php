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
 * JWT token manager for video access authentication.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream\auth;

use local_cloudflarestream\video_manager;
use local_cloudflarestream\config_manager;
use local_cloudflarestream\token_manager as TokenDB;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages JWT tokens for secure video access.
 */
class token_manager {

    /** @var string JWT algorithm */
    const JWT_ALGORITHM = 'HS256';

    /** @var string Token type */
    const TOKEN_TYPE = 'JWT';

    /**
     * Generate JWT token for video access.
     *
     * @param int $userid User ID
     * @param int $videoid Video record ID
     * @param array $options Additional token options
     * @return array Token generation result
     */
    public static function generate_video_token($userid, $videoid, $options = []) {
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
                return [
                    'success' => false,
                    'error' => 'Video is not ready for streaming'
                ];
            }

            // Verify user has access to video
            if (!self::can_user_access_video($userid, $video)) {
                return [
                    'success' => false,
                    'error' => get_string('error_insufficient_permissions', 'local_cloudflarestream')
                ];
            }

            // Generate token
            $expiry = time() + config_manager::get('token_expiry', 3600);
            $payload = self::create_token_payload($userid, $videoid, $video, $expiry, $options);
            $token = self::encode_jwt($payload);

            // Store token in database
            $tokenhash = hash('sha256', $token);
            $tokenid = TokenDB::create_token(
                $userid,
                $videoid,
                $tokenhash,
                $expiry,
                self::get_client_ip(),
                self::get_user_agent()
            );

            return [
                'success' => true,
                'token' => $token,
                'expires_at' => $expiry,
                'video_id' => $video->cloudflare_video_id
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Validate JWT token.
     *
     * @param string $token JWT token
     * @return array Validation result
     */
    public static function validate_token($token) {
        try {
            // Decode JWT
            $payload = self::decode_jwt($token);
            if (!$payload) {
                return [
                    'valid' => false,
                    'error' => get_string('error_invalid_token', 'local_cloudflarestream')
                ];
            }

            // Check token in database
            $tokenhash = hash('sha256', $token);
            $tokenrecord = TokenDB::validate_token($tokenhash);
            
            if (!$tokenrecord) {
                return [
                    'valid' => false,
                    'error' => get_string('error_invalid_token', 'local_cloudflarestream')
                ];
            }

            // Verify payload matches database record
            if ($payload['sub'] != $tokenrecord->user_id || $payload['video_id'] != $tokenrecord->video_id) {
                return [
                    'valid' => false,
                    'error' => 'Token payload mismatch'
                ];
            }

            // Additional security checks
            $securitycheck = self::perform_security_checks($payload, $tokenrecord);
            if (!$securitycheck['valid']) {
                return $securitycheck;
            }

            return [
                'valid' => true,
                'payload' => $payload,
                'token_record' => $tokenrecord
            ];

        } catch (\Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate signed URL for Cloudflare Stream.
     *
     * @param int $videoid Video record ID
     * @param int $userid User ID
     * @param array $options URL options
     * @return array Signed URL result
     */
    public static function generate_signed_url($videoid, $userid, $options = []) {
        // First generate our internal token
        $tokenresult = self::generate_video_token($userid, $videoid, $options);
        if (!$tokenresult['success']) {
            return $tokenresult;
        }

        try {
            // Get Cloudflare Stream manager
            $streammanager = \local_cloudflarestream\api\stream_manager::get_instance();
            if (!$streammanager) {
                return [
                    'success' => false,
                    'error' => 'Stream manager not available'
                ];
            }

            // Generate Cloudflare signed URL
            $expiry = $tokenresult['expires_at'];
            $cloudflareoptions = [
                'downloadable' => $options['downloadable'] ?? false
            ];

            $client = \local_cloudflarestream\api\cloudflare_client::get_instance();
            $urlresult = $client->generate_signed_url($tokenresult['video_id'], $expiry, $cloudflareoptions);

            if (!$urlresult['success']) {
                return [
                    'success' => false,
                    'error' => 'Failed to generate Cloudflare signed URL'
                ];
            }

            return [
                'success' => true,
                'signed_url' => $urlresult['result']['token'],
                'expires_at' => $expiry,
                'internal_token' => $tokenresult['token']
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Revoke token.
     *
     * @param string $token JWT token
     * @return bool Success
     */
    public static function revoke_token($token) {
        try {
            $tokenhash = hash('sha256', $token);
            return TokenDB::delete_token_by_hash($tokenhash);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Create JWT payload.
     *
     * @param int $userid User ID
     * @param int $videoid Video record ID
     * @param \stdClass $video Video record
     * @param int $expiry Expiry timestamp
     * @param array $options Additional options
     * @return array JWT payload
     */
    private static function create_token_payload($userid, $videoid, $video, $expiry, $options = []) {
        global $CFG;

        $payload = [
            'iss' => $CFG->wwwroot, // Issuer
            'aud' => 'cloudflare_stream', // Audience
            'sub' => $userid, // Subject (user ID)
            'exp' => $expiry, // Expiration time
            'iat' => time(), // Issued at
            'nbf' => time(), // Not before
            'jti' => uniqid('cf_', true), // JWT ID
            'video_id' => $videoid, // Internal video ID
            'cloudflare_video_id' => $video->cloudflare_video_id,
            'course_id' => $video->course_id,
            'permissions' => self::get_user_permissions($userid, $video),
        ];

        // Add optional claims
        if (isset($options['ip_restriction'])) {
            $payload['ip'] = self::get_client_ip();
        }

        if (isset($options['user_agent_restriction'])) {
            $payload['ua'] = hash('sha256', self::get_user_agent());
        }

        return $payload;
    }

    /**
     * Encode JWT token.
     *
     * @param array $payload JWT payload
     * @return string JWT token
     */
    private static function encode_jwt($payload) {
        $header = [
            'typ' => self::TOKEN_TYPE,
            'alg' => self::JWT_ALGORITHM
        ];

        $headerencoded = self::base64url_encode(json_encode($header));
        $payloadencoded = self::base64url_encode(json_encode($payload));
        
        $signature = hash_hmac('sha256', $headerencoded . '.' . $payloadencoded, self::get_jwt_secret(), true);
        $signatureencoded = self::base64url_encode($signature);

        return $headerencoded . '.' . $payloadencoded . '.' . $signatureencoded;
    }

    /**
     * Decode JWT token.
     *
     * @param string $token JWT token
     * @return array|false JWT payload or false if invalid
     */
    private static function decode_jwt($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        list($headerencoded, $payloadencoded, $signatureencoded) = $parts;

        // Verify signature
        $signature = self::base64url_decode($signatureencoded);
        $expectedsignature = hash_hmac('sha256', $headerencoded . '.' . $payloadencoded, self::get_jwt_secret(), true);

        if (!hash_equals($signature, $expectedsignature)) {
            return false;
        }

        // Decode header and payload
        $header = json_decode(self::base64url_decode($headerencoded), true);
        $payload = json_decode(self::base64url_decode($payloadencoded), true);

        if (!$header || !$payload) {
            return false;
        }

        // Verify header
        if ($header['typ'] !== self::TOKEN_TYPE || $header['alg'] !== self::JWT_ALGORITHM) {
            return false;
        }

        // Verify timing claims
        $now = time();
        if (isset($payload['exp']) && $now >= $payload['exp']) {
            return false; // Token expired
        }
        if (isset($payload['nbf']) && $now < $payload['nbf']) {
            return false; // Token not yet valid
        }

        return $payload;
    }

    /**
     * Check if user can access video.
     *
     * @param int $userid User ID
     * @param \stdClass $video Video record
     * @return bool True if user has access
     */
    private static function can_user_access_video($userid, $video) {
        global $DB;

        // Check if user is the owner
        if ($video->user_id == $userid) {
            return true;
        }

        // Check course enrollment
        $context = \context_course::instance($video->course_id);
        if (is_enrolled($context, $userid)) {
            return true;
        }

        // Check if user has admin capabilities
        if (has_capability('moodle/site:config', \context_system::instance(), $userid)) {
            return true;
        }

        return false;
    }

    /**
     * Get user permissions for video.
     *
     * @param int $userid User ID
     * @param \stdClass $video Video record
     * @return array User permissions
     */
    private static function get_user_permissions($userid, $video) {
        $permissions = ['view' => true];

        // Check if user can download
        $context = \context_course::instance($video->course_id);
        if (has_capability('moodle/course:managefiles', $context, $userid)) {
            $permissions['download'] = true;
        }

        // Check if user can manage
        if ($video->user_id == $userid || has_capability('moodle/site:config', \context_system::instance(), $userid)) {
            $permissions['manage'] = true;
        }

        return $permissions;
    }

    /**
     * Perform additional security checks.
     *
     * @param array $payload JWT payload
     * @param \stdClass $tokenrecord Token database record
     * @return array Security check result
     */
    private static function perform_security_checks($payload, $tokenrecord) {
        // Check IP restriction if enabled
        if (isset($payload['ip'])) {
            $currentip = self::get_client_ip();
            if ($payload['ip'] !== $currentip) {
                return [
                    'valid' => false,
                    'error' => 'IP address mismatch'
                ];
            }
        }

        // Check user agent restriction if enabled
        if (isset($payload['ua'])) {
            $currentua = hash('sha256', self::get_user_agent());
            if ($payload['ua'] !== $currentua) {
                return [
                    'valid' => false,
                    'error' => 'User agent mismatch'
                ];
            }
        }

        // Rate limiting check
        if (!self::check_rate_limit($tokenrecord->user_id)) {
            return [
                'valid' => false,
                'error' => 'Rate limit exceeded'
            ];
        }

        return ['valid' => true];
    }

    /**
     * Check rate limit for user.
     *
     * @param int $userid User ID
     * @return bool True if within limits
     */
    private static function check_rate_limit($userid) {
        // Simple rate limiting: max 100 token validations per hour
        $cachekey = 'cloudflarestream_rate_limit_' . $userid;
        $cache = \cache::make('local_cloudflarestream', 'tokens');
        
        $count = $cache->get($cachekey) ?: 0;
        if ($count >= 100) {
            return false;
        }

        $cache->set($cachekey, $count + 1, 3600); // 1 hour TTL
        return true;
    }

    /**
     * Get JWT secret key.
     *
     * @return string Secret key
     */
    private static function get_jwt_secret() {
        global $CFG;
        
        // Use a combination of site-specific values for the secret
        return hash('sha256', $CFG->wwwroot . $CFG->dataroot . 'cloudflarestream_jwt_secret');
    }

    /**
     * Get client IP address.
     *
     * @return string IP address
     */
    private static function get_client_ip() {
        return getremoteaddr();
    }

    /**
     * Get user agent string.
     *
     * @return string User agent
     */
    private static function get_user_agent() {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    }

    /**
     * Base64 URL encode.
     *
     * @param string $data Data to encode
     * @return string Encoded data
     */
    private static function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL decode.
     *
     * @param string $data Data to decode
     * @return string Decoded data
     */
    private static function base64url_decode($data) {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }

    /**
     * Clean up expired tokens.
     *
     * @param int $batchsize Number of tokens to clean per batch
     * @return int Number of tokens cleaned
     */
    public static function cleanup_expired_tokens($batchsize = 1000) {
        return TokenDB::cleanup_expired_tokens($batchsize);
    }

    /**
     * Get token statistics.
     *
     * @param int $days Number of days to look back
     * @return array Token statistics
     */
    public static function get_token_statistics($days = 30) {
        return TokenDB::get_token_statistics($days);
    }
}