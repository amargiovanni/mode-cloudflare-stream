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
 * Unit tests for config_manager class.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

/**
 * Test cases for config_manager class.
 *
 * @group local_cloudflarestream
 */
class config_manager_test extends advanced_testcase {

    /**
     * Set up test environment.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
    }

    /**
     * Test getting default configuration values.
     */
    public function test_get_default_values() {
        $this->assertEquals(524288000, config_manager::get('max_file_size'));
        $this->assertEquals('mp4,mov,avi,mkv,webm', config_manager::get('supported_formats'));
        $this->assertEquals(3600, config_manager::get('token_expiry'));
        $this->assertEquals(604800, config_manager::get('cleanup_delay'));
    }

    /**
     * Test setting and getting configuration values.
     */
    public function test_set_and_get_values() {
        config_manager::set('max_file_size', 1000000);
        $this->assertEquals(1000000, config_manager::get('max_file_size'));

        config_manager::set('supported_formats', 'mp4,webm');
        $this->assertEquals('mp4,webm', config_manager::get('supported_formats'));
    }

    /**
     * Test configuration validation.
     */
    public function test_validate_config() {
        // Test with missing required keys
        $config = [];
        $validation = config_manager::validate_config($config);
        $this->assertFalse($validation['valid']);
        $this->assertNotEmpty($validation['errors']);

        // Test with valid configuration
        $config = [
            'api_token' => 'test_token_1234567890abcdef1234567890abcdef12345678',
            'account_id' => 'abcdef1234567890abcdef1234567890',
            'max_file_size' => 1000000,
            'supported_formats' => 'mp4,webm',
            'token_expiry' => 3600,
            'cleanup_delay' => 86400
        ];
        $validation = config_manager::validate_config($config);
        $this->assertTrue($validation['valid']);
        $this->assertEmpty($validation['errors']);
    }

    /**
     * Test API token validation.
     */
    public function test_validate_api_token() {
        // Test empty token
        $result = config_manager::validate_config_value('api_token', '');
        $this->assertFalse($result['valid']);

        // Test short token
        $result = config_manager::validate_config_value('api_token', 'short');
        $this->assertFalse($result['valid']);

        // Test invalid characters
        $result = config_manager::validate_config_value('api_token', 'invalid@token#with$special%chars');
        $this->assertFalse($result['valid']);

        // Test valid token
        $result = config_manager::validate_config_value('api_token', 'valid_token_1234567890abcdef1234567890abcdef12345678');
        $this->assertTrue($result['valid']);
    }

    /**
     * Test account ID validation.
     */
    public function test_validate_account_id() {
        // Test empty ID
        $result = config_manager::validate_config_value('account_id', '');
        $this->assertFalse($result['valid']);

        // Test wrong length
        $result = config_manager::validate_config_value('account_id', 'short');
        $this->assertFalse($result['valid']);

        // Test invalid characters
        $result = config_manager::validate_config_value('account_id', 'ABCDEF1234567890abcdef1234567890');
        $this->assertFalse($result['valid']);

        // Test valid ID
        $result = config_manager::validate_config_value('account_id', 'abcdef1234567890abcdef1234567890');
        $this->assertTrue($result['valid']);
    }

    /**
     * Test file size validation.
     */
    public function test_validate_file_size() {
        // Test non-numeric value
        $result = config_manager::validate_config_value('max_file_size', 'not_a_number');
        $this->assertFalse($result['valid']);

        // Test zero value
        $result = config_manager::validate_config_value('max_file_size', 0);
        $this->assertFalse($result['valid']);

        // Test negative value
        $result = config_manager::validate_config_value('max_file_size', -1000);
        $this->assertFalse($result['valid']);

        // Test too large value
        $result = config_manager::validate_config_value('max_file_size', 6000000000); // 6GB
        $this->assertFalse($result['valid']);

        // Test valid value
        $result = config_manager::validate_config_value('max_file_size', 1000000);
        $this->assertTrue($result['valid']);
    }

    /**
     * Test supported formats validation.
     */
    public function test_validate_supported_formats() {
        // Test empty formats
        $result = config_manager::validate_config_value('supported_formats', '');
        $this->assertFalse($result['valid']);

        // Test invalid format
        $result = config_manager::validate_config_value('supported_formats', 'mp4,invalid_format');
        $this->assertFalse($result['valid']);

        // Test valid formats
        $result = config_manager::validate_config_value('supported_formats', 'mp4,mov,avi');
        $this->assertTrue($result['valid']);
    }

    /**
     * Test token expiry validation.
     */
    public function test_validate_token_expiry() {
        // Test non-numeric value
        $result = config_manager::validate_config_value('token_expiry', 'not_a_number');
        $this->assertFalse($result['valid']);

        // Test too short expiry
        $result = config_manager::validate_config_value('token_expiry', 60); // 1 minute
        $this->assertFalse($result['valid']);

        // Test too long expiry
        $result = config_manager::validate_config_value('token_expiry', 90000); // 25 hours
        $this->assertFalse($result['valid']);

        // Test valid expiry
        $result = config_manager::validate_config_value('token_expiry', 3600); // 1 hour
        $this->assertTrue($result['valid']);
    }

    /**
     * Test cleanup delay validation.
     */
    public function test_validate_cleanup_delay() {
        // Test non-numeric value
        $result = config_manager::validate_config_value('cleanup_delay', 'not_a_number');
        $this->assertFalse($result['valid']);

        // Test too short delay
        $result = config_manager::validate_config_value('cleanup_delay', 1800); // 30 minutes
        $this->assertFalse($result['valid']);

        // Test valid delay
        $result = config_manager::validate_config_value('cleanup_delay', 86400); // 24 hours
        $this->assertTrue($result['valid']);
    }

    /**
     * Test getting all configuration values.
     */
    public function test_get_all() {
        config_manager::set('api_token', 'test_token');
        config_manager::set('account_id', 'abcdef1234567890abcdef1234567890');

        $config = config_manager::get_all();
        
        $this->assertIsArray($config);
        $this->assertArrayHasKey('api_token', $config);
        $this->assertArrayHasKey('account_id', $config);
        $this->assertArrayHasKey('max_file_size', $config);
        
        // Check defaults are included
        $this->assertEquals(524288000, $config['max_file_size']);
    }

    /**
     * Test configuration status.
     */
    public function test_is_configured() {
        // Initially not configured
        $this->assertFalse(config_manager::is_configured());

        // Set required configuration
        config_manager::set('api_token', 'test_token_1234567890abcdef1234567890abcdef12345678');
        config_manager::set('account_id', 'abcdef1234567890abcdef1234567890');

        $this->assertTrue(config_manager::is_configured());
    }

    /**
     * Test getting configuration status.
     */
    public function test_get_status() {
        $status = config_manager::get_status();
        
        $this->assertIsArray($status);
        $this->assertArrayHasKey('configured', $status);
        $this->assertArrayHasKey('valid', $status);
        $this->assertArrayHasKey('errors', $status);
        $this->assertArrayHasKey('warnings', $status);
        
        // Initially should not be configured
        $this->assertFalse($status['configured']);
    }
}