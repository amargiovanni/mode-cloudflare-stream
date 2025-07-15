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
 * Admin interface JavaScript for Cloudflare Stream plugin.
 *
 * @module     local_cloudflarestream/admin
 * @copyright  2025 Your Name <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/notification', 'core/str'], function($, Ajax, Notification, Str) {
    'use strict';

    /**
     * Initialize the admin interface.
     */
    function init() {
        // Test connection button handler
        $('#cloudflarestream-test-btn').on('click', function(e) {
            e.preventDefault();
            testConnection();
        });

        // Auto-test when credentials change (with debounce)
        var testTimeout;
        $('#id_s_local_cloudflarestream_api_token, #id_s_local_cloudflarestream_account_id').on('input', function() {
            clearTimeout(testTimeout);
            testTimeout = setTimeout(function() {
                var apiToken = $('#id_s_local_cloudflarestream_api_token').val();
                var accountId = $('#id_s_local_cloudflarestream_account_id').val();
                
                if (apiToken.length > 10 && accountId.length > 10) {
                    testConnection();
                }
            }, 2000); // 2 second delay
        });
    }

    /**
     * Test the Cloudflare API connection.
     */
    function testConnection() {
        var $button = $('#cloudflarestream-test-btn');
        var $result = $('#cloudflarestream-test-result');
        
        // Get current form values
        var apiToken = $('#id_s_local_cloudflarestream_api_token').val();
        var accountId = $('#id_s_local_cloudflarestream_account_id').val();
        var zoneId = $('#id_s_local_cloudflarestream_zone_id').val();

        // Update UI to show testing state
        $button.prop('disabled', true);
        $button.text(M.util.get_string('test_connection_testing', 'local_cloudflarestream'));
        $result.html('<div class="alert alert-info"><i class="fa fa-spinner fa-spin"></i> ' + 
                    M.util.get_string('test_connection_testing', 'local_cloudflarestream') + '</div>');

        // Make AJAX request
        $.ajax({
            url: M.cfg.wwwroot + '/local/cloudflarestream/test_connection.php',
            type: 'POST',
            data: {
                api_token: apiToken,
                account_id: accountId,
                zone_id: zoneId,
                sesskey: M.cfg.sesskey
            },
            dataType: 'json',
            timeout: 30000 // 30 seconds
        })
        .done(function(response) {
            if (response.success) {
                $result.html('<div class="alert alert-success"><i class="fa fa-check"></i> ' + 
                           response.message + '</div>');
            } else {
                $result.html('<div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> ' + 
                           response.error + '</div>');
            }
        })
        .fail(function(xhr, status, error) {
            var errorMsg = M.util.get_string('test_connection_failed', 'local_cloudflarestream');
            if (status === 'timeout') {
                errorMsg += ' (Connection timeout)';
            } else if (xhr.responseJSON && xhr.responseJSON.error) {
                errorMsg += ': ' + xhr.responseJSON.error;
            } else {
                errorMsg += ': ' + error;
            }
            
            $result.html('<div class="alert alert-danger"><i class="fa fa-exclamation-triangle"></i> ' + 
                        errorMsg + '</div>');
        })
        .always(function() {
            // Reset button state
            $button.prop('disabled', false);
            $button.text(M.util.get_string('test_connection_button', 'local_cloudflarestream'));
        });
    }

    return {
        init: init
    };
});