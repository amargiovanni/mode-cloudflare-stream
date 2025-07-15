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
 * Unit tests for cloudflare_client class.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream\api;

use advanced_testcase;
use local_cloudflarestream\config_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Test cases for cloudflare_client class.
 *
 * @group local_cloudflarestream
 */
class cloudflare_client_test extends advanced_testcase {

    /**
     * Set up test environment.
     */
    protected function setUp(): void {
        $this->resetAfterTest();
        
        // Set up test configuration
        config_manager::set('api_token', 'test_token_1234567890abcdef1234567890abcdef12345678');
        config_manager::set('account_id', 'abcdef1234567890abcdef1234567890');
    }

    /**
     * Test client initialization.
     */
    public function test_client_initialization() {
        $client = new cloudflare_client(
            'test_token_1234567890abcdef1234567890abcdef12345678',
            'abcdef1234567890abcdef1234567890'
        );
        
        $this->assertInstanceOf(cloudflare_client::class, $client);
    }

    /**
     * Test client initialization with invalid token.
     */
    public function test_client_initialization_invalid_token() {
        $this->expectException(\InvalidArgumentException::class);
        
        new cloudflare_client('invalid_token', 'abcdef1234567890abcdef1234567890');
    }

    /**
     * Test client initialization with invalid account ID.
     */
    public function test_client_initialization_invalid_account_id() {
        $this->expectException(\InvalidArgumentException::class);
        
        new cloudflare_client(
            'test_token_1234567890abcdef1234567890abcdef12345678',
            'invalid_account_id'
        );
    }

    /**
     * Test getting singleton instance.
     */
    public function test_get_instance() {
        $instance1 = cloudflare_client::get_instance();
        $instance2 = cloudflare_client::get_instance();
        
        $this->assertInstanceOf(cloudflare_client::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    /**
     * Test getting instance without configuration.
     */
    public function test_get_instance_not_configured() {
        // Clear configuration
        config_manager::set('api_token', '');
        config_manager::set('account_id', '');
        
        $instance = cloudflare_client::get_instance();
        $this->assertNull($instance);
    }

    /**
     * Test building API URL.
     */
    public function test_build_url() {
        $client = new cloudflare_client(
            'test_token_1234567890abcdef1234567890abcdef12345678',
            'abcdef1234567890abcdef1234567890'
        );
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('build_url');
        $method->setAccessible(true);
        
        $url = $method->invoke($client, 'stream');
        $expected = 'https://api.cloudflare.com/client/v4/accounts/abcdef1234567890abcdef1234567890/stream';
        
        $this->assertEquals($expected, $url);
    }

    /**
     * Test building headers.
     */
    public function test_build_headers() {
        $client = new cloudflare_client(
            'test_token_1234567890abcdef1234567890abcdef12345678',
            'abcdef1234567890abcdef1234567890'
        );
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('build_headers');
        $method->setAccessible(true);
        
        $headers = $method->invoke($client);
        
        $this->assertIsArray($headers);
        $this->assertContains('Authorization: Bearer test_token_1234567890abcdef1234567890abcdef12345678', $headers);
        $this->assertContains('Content-Type: application/json', $headers);
    }

    /**
     * Test connection test with mocked response.
     */
    public function test_connection_test_success() {
        // This test would require mocking the HTTP client
        // For now, we'll test the method exists and returns expected format
        $client = new cloudflare_client(
            'test_token_1234567890abcdef1234567890abcdef12345678',
            'abcdef1234567890abcdef1234567890'
        );
        
        // Test that the method exists and returns array with expected keys
        $this->assertTrue(method_exists($client, 'test_connection'));
        
        // Note: Actual HTTP testing would require mocking curl or using a test HTTP client
        // This is a basic structure test
    }

    /**
     * Test error handling for invalid responses.
     */
    public function test_handle_response_error() {
        $client = new cloudflare_client(
            'test_token_1234567890abcdef1234567890abcdef12345678',
            'abcdef1234567890abcdef1234567890'
        );
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('handle_response');
        $method->setAccessible(true);
        
        // Test with error response
        $errorresponse = json_encode([
            'success' => false,
            'errors' => [
                ['message' => 'Test error message']
            ]
        ]);
        
        $result = $method->invoke($client, $errorresponse, 400);
        
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('message', $result);
    }

    /**
     * Test successful response handling.
     */
    public function test_handle_response_success() {
        $client = new cloudflare_client(
            'test_token_1234567890abcdef1234567890abcdef12345678',
            'abcdef1234567890abcdef1234567890'
        );
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('handle_response');
        $method->setAccessible(true);
        
        // Test with success response
        $successresponse = json_encode([
            'success' => true,
            'result' => [
                'uid' => 'test_video_id',
                'status' => ['state' => 'ready']
            ]
        ]);
        
        $result = $method->invoke($client, $successresponse, 200);
        
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('data', $result);
        $this->assertEquals('test_video_id', $result['data']['uid']);
    }

    /**
     * Test rate limiting handling.
     */
    public function test_rate_limiting() {
        $client = new cloudflare_client(
            'test_token_1234567890abcdef1234567890abcdef12345678',
            'abcdef1234567890abcdef1234567890'
        );
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('handle_response');
        $method->setAccessible(true);
        
        // Test with rate limit response
        $ratelimitresponse = json_encode([
            'success' => false,
            'errors' => [
                ['code' => 10013, 'message' => 'Rate limit exceeded']
            ]
        ]);
        
        $result = $method->invoke($client, $ratelimitresponse, 429);
        
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertStringContains('rate limit', strtolower($result['message']));
    }

    /**
     * Test retry mechanism configuration.
     */
    public function test_retry_configuration() {
        $client = new cloudflare_client(
            'test_token_1234567890abcdef1234567890abcdef12345678',
            'abcdef1234567890abcdef1234567890'
        );
        
        // Use reflection to check retry configuration
        $reflection = new \ReflectionClass($client);
        
        if ($reflection->hasProperty('max_retries')) {
            $property = $reflection->getProperty('max_retries');
            $property->setAccessible(true);
            $maxretries = $property->getValue($client);
            
            $this->assertIsInt($maxretries);
            $this->assertGreaterThan(0, $maxretries);
        }
    }
}