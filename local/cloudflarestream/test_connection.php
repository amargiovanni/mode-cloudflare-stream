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
 * AJAX endpoint for testing Cloudflare API connection.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_cloudflarestream\api\cloudflare_client;

// Check permissions
require_login();
require_capability('moodle/site:config', context_system::instance());

// Verify CSRF token
if (!confirm_sesskey()) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Invalid session key']));
}

// Set JSON header
header('Content-Type: application/json');

try {
    // Get credentials from form data or current config
    $apitoken = optional_param('api_token', '', PARAM_RAW);
    $accountid = optional_param('account_id', '', PARAM_ALPHANUM);
    $zoneid = optional_param('zone_id', '', PARAM_ALPHANUM);

    // If no credentials provided, use current config
    if (empty($apitoken)) {
        $apitoken = get_config('local_cloudflarestream', 'api_token');
    }
    if (empty($accountid)) {
        $accountid = get_config('local_cloudflarestream', 'account_id');
    }
    if (empty($zoneid)) {
        $zoneid = get_config('local_cloudflarestream', 'zone_id');
    }

    // Validate required fields
    if (empty($apitoken) || empty($accountid)) {
        echo json_encode([
            'success' => false,
            'error' => get_string('error_api_connection', 'local_cloudflarestream') . ': Missing API token or Account ID'
        ]);
        exit;
    }

    // Create client and test connection
    $client = new cloudflare_client($apitoken, $accountid, $zoneid);
    $result = $client->test_connection();

    echo json_encode($result);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => get_string('error_api_connection', 'local_cloudflarestream') . ': ' . $e->getMessage()
    ]);
}