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

    /**
     * Initialize dashboard functionality.
     */
    function initDashboard() {
        // Auto-refresh dashboard every 30 seconds
        setInterval(function() {
            refreshDashboardData();
        }, 30000);

        // Set up action confirmations
        $('.admin-action-btn').on('click', function(e) {
            var action = $(this).data('action');
            var title = $(this).data('title');
            
            if (!confirm('Are you sure you want to ' + title.toLowerCase() + '?')) {
                e.preventDefault();
                return false;
            }
        });

        // Set up real-time updates
        setupRealTimeUpdates();
    }

    /**
     * Refresh dashboard data.
     */
    function refreshDashboardData() {
        // Update statistics cards
        updateStatisticsCards();
        
        // Update system status
        updateSystemStatus();
        
        // Update queue status
        updateQueueStatus();
    }

    /**
     * Update statistics cards.
     */
    function updateStatisticsCards() {
        $.ajax({
            url: M.cfg.wwwroot + '/local/cloudflarestream/ajax/dashboard_data.php',
            type: 'POST',
            data: {
                action: 'get_statistics',
                sesskey: M.cfg.sesskey
            },
            dataType: 'json'
        }).done(function(response) {
            if (response.success) {
                updateCardValues(response.data);
            }
        }).fail(function() {
            console.error('Failed to update statistics');
        });
    }

    /**
     * Update card values.
     *
     * @param {Object} data Statistics data
     */
    function updateCardValues(data) {
        // Update each card with new values
        $('.dashboard-card[data-metric]').each(function() {
            var metric = $(this).data('metric');
            var value = data[metric];
            
            if (value !== undefined) {
                $(this).find('.card-title').text(value);
            }
        });
    }

    /**
     * Update system status.
     */
    function updateSystemStatus() {
        $.ajax({
            url: M.cfg.wwwroot + '/local/cloudflarestream/ajax/dashboard_data.php',
            type: 'POST',
            data: {
                action: 'get_system_status',
                sesskey: M.cfg.sesskey
            },
            dataType: 'json'
        }).done(function(response) {
            if (response.success) {
                updateStatusBadges(response.data);
            }
        }).fail(function() {
            console.error('Failed to update system status');
        });
    }

    /**
     * Update status badges.
     *
     * @param {Object} status Status data
     */
    function updateStatusBadges(status) {
        // Update configuration status
        var configBadge = $('.status-badge[data-status="configured"]');
        if (status.configured) {
            configBadge.removeClass('badge-danger').addClass('badge-success').text('Configured');
        } else {
            configBadge.removeClass('badge-success').addClass('badge-danger').text('Not Configured');
        }

        // Update API connection status
        var apiBadge = $('.status-badge[data-status="api_connected"]');
        if (status.api_connected) {
            apiBadge.removeClass('badge-danger').addClass('badge-success').text('Connected');
        } else {
            apiBadge.removeClass('badge-success').addClass('badge-danger').text('Failed');
        }
    }

    /**
     * Update queue status.
     */
    function updateQueueStatus() {
        $.ajax({
            url: M.cfg.wwwroot + '/local/cloudflarestream/ajax/dashboard_data.php',
            type: 'POST',
            data: {
                action: 'get_queue_status',
                sesskey: M.cfg.sesskey
            },
            dataType: 'json'
        }).done(function(response) {
            if (response.success) {
                updateQueueNumbers(response.data);
            }
        }).fail(function() {
            console.error('Failed to update queue status');
        });
    }

    /**
     * Update queue numbers.
     *
     * @param {Object} queue Queue data
     */
    function updateQueueNumbers(queue) {
        $('.queue-total').text(queue.total);
        $('.queue-pending').text(queue.pending);
        $('.queue-failed').text(queue.failed);
    }

    /**
     * Set up real-time updates for processing videos.
     */
    function setupRealTimeUpdates() {
        // Check for videos in processing state and update them
        $('.video-status[data-status="processing"], .video-status[data-status="uploading"]').each(function() {
            var videoId = $(this).data('video-id');
            var statusElement = $(this);
            
            // Check status every 10 seconds for processing videos
            setInterval(function() {
                updateVideoStatus(videoId, statusElement);
            }, 10000);
        });
    }

    /**
     * Update individual video status.
     *
     * @param {number} videoId Video ID
     * @param {jQuery} statusElement Status element
     */
    function updateVideoStatus(videoId, statusElement) {
        $.ajax({
            url: M.cfg.wwwroot + '/local/cloudflarestream/upload_status.php',
            type: 'POST',
            data: {
                action: 'get_status',
                video_id: videoId,
                sesskey: M.cfg.sesskey
            },
            dataType: 'json'
        }).done(function(response) {
            if (response.status && response.status !== statusElement.data('status')) {
                // Status changed, update the display
                statusElement.data('status', response.status);
                statusElement.removeClass().addClass('badge video-status').addClass('badge-' + getStatusClass(response.status));
                statusElement.text(getStatusText(response.status));
                
                // If video is now ready, refresh the page to show updated info
                if (response.status === 'ready') {
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                }
            }
        }).fail(function() {
            console.error('Failed to update video status for video ' + videoId);
        });
    }

    /**
     * Get CSS class for status.
     *
     * @param {string} status Video status
     * @return {string} CSS class
     */
    function getStatusClass(status) {
        switch (status) {
            case 'ready':
                return 'success';
            case 'processing':
            case 'uploading':
                return 'warning';
            case 'error':
                return 'danger';
            default:
                return 'secondary';
        }
    }

    /**
     * Get status text.
     *
     * @param {string} status Video status
     * @return {string} Status text
     */
    function getStatusText(status) {
        switch (status) {
            case 'pending':
                return 'Pending';
            case 'uploading':
                return 'Uploading';
            case 'processing':
                return 'Processing';
            case 'ready':
                return 'Ready';
            case 'error':
                return 'Error';
            default:
                return 'Unknown';
        }
    }

    return {
        init: init,
        initDashboard: initDashboard
    };
});