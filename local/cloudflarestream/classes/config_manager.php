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
 * Configuration manager for Cloudflare Stream plugin.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream;

use local_cloudflarestream\api\cloudflare_client;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages plugin configuration and validation.
 */
class config_manager {

    /** @var string Plugin component name */
    const COMPONENT = 'local_cloudflarestream';

    /** @var array Default configuration values */
    const DEFAULTS = [
        'max_file_size' => 524288000, // 500MB
        'supported_formats' => 'mp4,mov,avi,mkv,webm',
        'token_expiry' => 3600, // 1 hour
        'player_controls' => 1,
        'autoplay' => 0,
        'cleanup_delay' => 604800, // 7 days
        'allowed_domains' => '',
        'allowed_referrers' => '',
        'enable_fallback_player' => 1,
        'domain_restrictions' => 0,
        'referrer_restrictions' => 0,
    ];

    /** @var array Required configuration keys */
    const REQUIRED_KEYS = ['api_token', 'account_id'];

    /**
     * Get configuration value.
     *
     * @param string $key Configuration key
     * @param mixed $default Default value if not set
     * @return mixed Configuration value
     */
    public static function get($key, $default = null) {
        $value = get_config(self::COMPONENT, $key);
        
        if ($value === false) {
            return $default ?? (self::DEFAULTS[$key] ?? null);
        }
        
        return $value;
    }

    /**
     * Set configuration value.
     *
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @return bool Success
     */
    public static function set($key, $value) {
        // Validate value before setting
        $validation = self::validate_config_value($key, $value);
        if (!$validation['valid']) {
            throw new \InvalidArgumentException($validation['error']);
        }

        // Encrypt sensitive values
        if (self::is_sensitive_key($key)) {
            $value = self::encrypt_value($value);
        }

        return set_config($key, $value, self::COMPONENT);
    }

    /**
     * Get all configuration values.
     *
     * @param bool $decrypt Whether to decrypt sensitive values
     * @return array Configuration array
     */
    public static function get_all($decrypt = false) {
        $config = get_config(self::COMPONENT);
        
        // Add defaults for missing values
        foreach (self::DEFAULTS as $key => $default) {
            if (!isset($config->$key)) {
                $config->$key = $default;
            }
        }

        // Decrypt sensitive values if requested
        if ($decrypt) {
            foreach ($config as $key => $value) {
                if (self::is_sensitive_key($key)) {
                    $config->$key = self::decrypt_value($value);
                }
            }
        }

        return (array)$config;
    }

    /**
     * Validate complete configuration.
     *
     * @param array $config Configuration to validate
     * @return array Validation result
     */
    public static function validate_config($config = null) {
        if ($config === null) {
            $config = self::get_all(true);
        }

        $errors = [];
        $warnings = [];

        // Check required keys
        foreach (self::REQUIRED_KEYS as $key) {
            if (empty($config[$key])) {
                $errors[] = "Missing required configuration: {$key}";
            }
        }

        // Validate individual values
        foreach ($config as $key => $value) {
            $validation = self::validate_config_value($key, $value);
            if (!$validation['valid']) {
                $errors[] = "Invalid {$key}: " . $validation['error'];
            }
        }

        // Additional validation checks
        if (!empty($config['api_token']) && !empty($config['account_id'])) {
            $apiValidation = self::validate_api_credentials($config['api_token'], $config['account_id'], $config['zone_id'] ?? '');
            if (!$apiValidation['valid']) {
                $errors[] = 'API credentials validation failed: ' . $apiValidation['error'];
            }
        }

        // Check file size limits
        if (!empty($config['max_file_size'])) {
            $maxUpload = self::get_php_max_upload_size();
            if ($config['max_file_size'] > $maxUpload) {
                $warnings[] = "Configured max file size ({$config['max_file_size']} bytes) exceeds PHP upload limit ({$maxUpload} bytes)";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Validate individual configuration value.
     *
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @return array Validation result
     */
    public static function validate_config_value($key, $value) {
        switch ($key) {
            case 'api_token':
                return self::validate_api_token($value);
            
            case 'account_id':
            case 'zone_id':
                return self::validate_id($value, $key);
            
            case 'max_file_size':
                return self::validate_file_size($value);
            
            case 'supported_formats':
                return self::validate_formats($value);
            
            case 'token_expiry':
                return self::validate_token_expiry($value);
            
            case 'cleanup_delay':
                return self::validate_cleanup_delay($value);
            
            case 'player_controls':
            case 'autoplay':
                return self::validate_boolean($value);
            
            default:
                return ['valid' => true];
        }
    }

    /**
     * Validate API token format.
     *
     * @param string $token API token
     * @return array Validation result
     */
    private static function validate_api_token($token) {
        if (empty($token)) {
            return ['valid' => false, 'error' => 'API token is required'];
        }

        if (strlen($token) < 40) {
            return ['valid' => false, 'error' => 'API token appears to be too short'];
        }

        // Basic format check for Cloudflare API tokens
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
            return ['valid' => false, 'error' => 'API token contains invalid characters'];
        }

        return ['valid' => true];
    }

    /**
     * Validate ID format (Account ID, Zone ID).
     *
     * @param string $id ID value
     * @param string $type ID type for error messages
     * @return array Validation result
     */
    private static function validate_id($id, $type) {
        if ($type === 'zone_id' && empty($id)) {
            return ['valid' => true]; // Zone ID is optional
        }

        if (empty($id)) {
            return ['valid' => false, 'error' => ucfirst($type) . ' is required'];
        }

        if (strlen($id) !== 32) {
            return ['valid' => false, 'error' => ucfirst($type) . ' must be 32 characters long'];
        }

        if (!preg_match('/^[a-f0-9]+$/', $id)) {
            return ['valid' => false, 'error' => ucfirst($type) . ' must contain only lowercase hexadecimal characters'];
        }

        return ['valid' => true];
    }

    /**
     * Validate file size setting.
     *
     * @param mixed $size File size value
     * @return array Validation result
     */
    private static function validate_file_size($size) {
        if (!is_numeric($size)) {
            return ['valid' => false, 'error' => 'File size must be a number'];
        }

        $size = (int)$size;
        if ($size <= 0) {
            return ['valid' => false, 'error' => 'File size must be greater than 0'];
        }

        if ($size > 5368709120) { // 5GB
            return ['valid' => false, 'error' => 'File size cannot exceed 5GB'];
        }

        return ['valid' => true];
    }

    /**
     * Validate supported formats setting.
     *
     * @param string $formats Comma-separated formats
     * @return array Validation result
     */
    private static function validate_formats($formats) {
        if (empty($formats)) {
            return ['valid' => false, 'error' => 'At least one video format must be supported'];
        }

        $formatList = array_map('trim', explode(',', strtolower($formats)));
        $validFormats = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'flv', 'wmv', 'm4v'];

        foreach ($formatList as $format) {
            if (!in_array($format, $validFormats)) {
                return ['valid' => false, 'error' => "Unsupported video format: {$format}"];
            }
        }

        return ['valid' => true];
    }

    /**
     * Validate token expiry setting.
     *
     * @param mixed $expiry Token expiry in seconds
     * @return array Validation result
     */
    private static function validate_token_expiry($expiry) {
        if (!is_numeric($expiry)) {
            return ['valid' => false, 'error' => 'Token expiry must be a number'];
        }

        $expiry = (int)$expiry;
        if ($expiry < 300) { // 5 minutes minimum
            return ['valid' => false, 'error' => 'Token expiry must be at least 300 seconds (5 minutes)'];
        }

        if ($expiry > 86400) { // 24 hours maximum
            return ['valid' => false, 'error' => 'Token expiry cannot exceed 86400 seconds (24 hours)'];
        }

        return ['valid' => true];
    }

    /**
     * Validate cleanup delay setting.
     *
     * @param mixed $delay Cleanup delay in seconds
     * @return array Validation result
     */
    private static function validate_cleanup_delay($delay) {
        if (!is_numeric($delay)) {
            return ['valid' => false, 'error' => 'Cleanup delay must be a number'];
        }

        $delay = (int)$delay;
        if ($delay < 3600) { // 1 hour minimum
            return ['valid' => false, 'error' => 'Cleanup delay must be at least 3600 seconds (1 hour)'];
        }

        return ['valid' => true];
    }

    /**
     * Validate boolean setting.
     *
     * @param mixed $value Boolean value
     * @return array Validation result
     */
    private static function validate_boolean($value) {
        if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'])) {
            return ['valid' => false, 'error' => 'Value must be boolean (0/1, true/false)'];
        }

        return ['valid' => true];
    }

    /**
     * Validate API credentials by testing connection.
     *
     * @param string $apitoken API token
     * @param string $accountid Account ID
     * @param string $zoneid Zone ID (optional)
     * @return array Validation result
     */
    private static function validate_api_credentials($apitoken, $accountid, $zoneid = '') {
        try {
            $client = new cloudflare_client($apitoken, $accountid, $zoneid);
            $result = $client->test_connection();
            
            return [
                'valid' => $result['success'],
                'error' => $result['success'] ? '' : $result['message']
            ];
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check if configuration key contains sensitive data.
     *
     * @param string $key Configuration key
     * @return bool True if sensitive
     */
    private static function is_sensitive_key($key) {
        return in_array($key, ['api_token']);
    }

    /**
     * Encrypt sensitive configuration value.
     *
     * @param string $value Value to encrypt
     * @return string Encrypted value
     */
    private static function encrypt_value($value) {
        // For now, just return the value as-is
        // In production, implement proper encryption
        return $value;
    }

    /**
     * Decrypt sensitive configuration value.
     *
     * @param string $value Encrypted value
     * @return string Decrypted value
     */
    private static function decrypt_value($value) {
        // For now, just return the value as-is
        // In production, implement proper decryption
        return $value;
    }

    /**
     * Get PHP maximum upload size.
     *
     * @return int Maximum upload size in bytes
     */
    private static function get_php_max_upload_size() {
        $maxUpload = self::parse_size(ini_get('upload_max_filesize'));
        $maxPost = self::parse_size(ini_get('post_max_size'));
        $memoryLimit = self::parse_size(ini_get('memory_limit'));

        return min($maxUpload, $maxPost, $memoryLimit);
    }

    /**
     * Parse size string to bytes.
     *
     * @param string $size Size string (e.g., '2M', '1G')
     * @return int Size in bytes
     */
    private static function parse_size($size) {
        $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
        $size = preg_replace('/[^0-9\.]/', '', $size);
        
        if ($unit) {
            return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
        }
        
        return round($size);
    }

    /**
     * Check if plugin is properly configured.
     *
     * @return bool True if configured
     */
    public static function is_configured() {
        $config = self::get_all(true);
        
        foreach (self::REQUIRED_KEYS as $key) {
            if (empty($config[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get configuration status summary.
     *
     * @return array Status information
     */
    public static function get_status() {
        $config = self::get_all(true);
        $validation = self::validate_config($config);
        
        return [
            'configured' => self::is_configured(),
            'valid' => $validation['valid'],
            'errors' => $validation['errors'],
            'warnings' => $validation['warnings'],
            'api_connected' => false, // Will be set by API test
        ];
    }
}