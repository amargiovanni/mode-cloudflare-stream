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
 * AJAX endpoint for refreshing video access tokens.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

use local_cloudflarestream\auth\token_manager;
use local_cloudflarestream\access_controller;

// Check authentication
require_login();

// Verify CSRF token
if (!confirm_sesskey()) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Invalid session key']));
}

// Set JSON header
header('Content-Type: application/json');

try {
    $videoid = required_param('videoid', PARAM_INT);
    
    // Generate new token
    $result = access_controller::generate_access_token($videoid);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'token' => $result['token'],
            'expires_at' => $result['expires_at']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $result['error']
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}