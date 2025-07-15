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
 * Cloudflare Stream player JavaScript module.
 *
 * @module     local_cloudflarestream/player
 * @copyright  2025 Your Name <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {
    'use strict';

    var players = {};

    /**
     * Initialize a Cloudflare Stream player.
     *
     * @param {Object} config Player configuration
     */
    function init(config) {
        var playerId = config.playerId;
        var player = {
            id: playerId,
            config: config,
            element: document.getElementById(playerId),
            tokenExpires: config.tokenExpires,
            refreshTimer: null
        };

        if (!player.element) {
            console.error('Cloudflare Stream player element not found:', playerId);
            return;
        }

        players[playerId] = player;

        // Set up token refresh
        setupTokenRefresh(player);

        // Set up event listeners
        setupEventListeners(player);

        // Set up error handling
        setupErrorHandling(player);

        console.log('Cloudflare Stream player initialized:', playerId);
    }

    /**
     * Set up automatic token refresh.
     *
     * @param {Object} player Player object
     */
    function setupTokenRefresh(player) {
        var now = Math.floor(Date.now() / 1000);
        var expiresIn = player.tokenExpires - now;
        var refreshTime = Math.max(expiresIn - 300, 60); // Refresh 5 minutes before expiry, minimum 1 minute

        if (refreshTime > 0) {
            player.refreshTimer = setTimeout(function() {
                refreshPlayerToken(player);
            }, refreshTime * 1000);
        }
    }

    /**
     * Refresh player access token.
     *
     * @param {Object} player Player object
     */
    function refreshPlayerToken(player) {
        Ajax.call([{
            methodname: 'local_cloudflarestream_refresh_token',
            args: {
                videoid: player.config.videoRecordId
            }
        }])[0].done(function(response) {
            if (response.success) {
                // Update token expiry
                player.tokenExpires = response.expires_at;
                
                // Set up next refresh
                setupTokenRefresh(player);
                
                console.log('Token refreshed for player:', player.id);
            } else {
                console.error('Failed to refresh token:', response.error);
                showPlayerError(player, 'Session expired. Please refresh the page.');
            }
        }).fail(function(error) {
            console.error('Token refresh failed:', error);
            showPlayerError(player, 'Unable to refresh session. Please refresh the page.');
        });
    }

    /**
     * Set up event listeners for the player.
     *
     * @param {Object} player Player object
     */
    function setupEventListeners(player) {
        // Listen for iframe load events
        if (player.element.tagName === 'IFRAME') {
            player.element.addEventListener('load', function() {
                console.log('Player iframe loaded:', player.id);
            });

            player.element.addEventListener('error', function() {
                console.error('Player iframe error:', player.id);
                showPlayerError(player, 'Failed to load video player.');
            });
        }

        // Listen for visibility changes to pause/resume
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                // Page is hidden, could pause player if needed
                console.log('Page hidden, player:', player.id);
            } else {
                // Page is visible, could resume player if needed
                console.log('Page visible, player:', player.id);
            }
        });
    }

    /**
     * Set up error handling for the player.
     *
     * @param {Object} player Player object
     */
    function setupErrorHandling(player) {
        // Set up a timeout to detect if player fails to load
        setTimeout(function() {
            if (player.element && !isPlayerLoaded(player)) {
                console.warn('Player may have failed to load:', player.id);
                // Could show a fallback or error message
            }
        }, 10000); // 10 seconds timeout
    }

    /**
     * Check if player has loaded successfully.
     *
     * @param {Object} player Player object
     * @return {boolean} True if player appears to be loaded
     */
    function isPlayerLoaded(player) {
        if (player.element.tagName === 'IFRAME') {
            // For iframe, we can't easily check content, so assume loaded if no error
            return true;
        }
        return false;
    }

    /**
     * Show error message in player area.
     *
     * @param {Object} player Player object
     * @param {string} message Error message
     */
    function showPlayerError(player, message) {
        var wrapper = player.element.closest('.cloudflare-stream-wrapper');
        if (wrapper) {
            var errorDiv = wrapper.querySelector('.cloudflare-stream-error');
            if (errorDiv) {
                errorDiv.querySelector('.error-message').textContent = message;
                errorDiv.style.display = 'block';
                player.element.style.display = 'none';
            }
        }
    }

    /**
     * Hide error message and show player.
     *
     * @param {Object} player Player object
     */
    function hidePlayerError(player) {
        var wrapper = player.element.closest('.cloudflare-stream-wrapper');
        if (wrapper) {
            var errorDiv = wrapper.querySelector('.cloudflare-stream-error');
            if (errorDiv) {
                errorDiv.style.display = 'none';
                player.element.style.display = 'block';
            }
        }
    }

    /**
     * Get player by ID.
     *
     * @param {string} playerId Player ID
     * @return {Object|null} Player object or null
     */
    function getPlayer(playerId) {
        return players[playerId] || null;
    }

    /**
     * Destroy player and clean up resources.
     *
     * @param {string} playerId Player ID
     */
    function destroyPlayer(playerId) {
        var player = players[playerId];
        if (player) {
            // Clear refresh timer
            if (player.refreshTimer) {
                clearTimeout(player.refreshTimer);
            }

            // Remove from players registry
            delete players[playerId];

            console.log('Player destroyed:', playerId);
        }
    }

    /**
     * Refresh all players on the page.
     */
    function refreshAllPlayers() {
        Object.keys(players).forEach(function(playerId) {
            var player = players[playerId];
            refreshPlayerToken(player);
        });
    }

    /**
     * Check video status and update player if needed.
     *
     * @param {string} playerId Player ID
     */
    function checkVideoStatus(playerId) {
        var player = players[playerId];
        if (!player) {
            return;
        }

        Ajax.call([{
            methodname: 'local_cloudflarestream_get_video_status',
            args: {
                videoid: player.config.videoRecordId
            }
        }])[0].done(function(response) {
            if (response.status === 'ready' && player.config.status !== 'ready') {
                // Video is now ready, reload the page to show player
                location.reload();
            }
        }).fail(function(error) {
            console.error('Status check failed:', error);
        });
    }

    /**
     * Set up automatic status checking for processing videos.
     */
    function setupStatusChecking() {
        // Check for status players that need monitoring
        $('.cloudflare-stream-status[data-status]').each(function() {
            var status = $(this).data('status');
            var videoId = $(this).data('video-id');

            if (['pending', 'uploading', 'processing'].includes(status)) {
                // Set up periodic status checking
                setInterval(function() {
                    checkVideoStatusById(videoId);
                }, 30000); // Check every 30 seconds
            }
        });
    }

    /**
     * Check video status by video ID.
     *
     * @param {number} videoId Video record ID
     */
    function checkVideoStatusById(videoId) {
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
            if (response.status === 'ready') {
                // Video is ready, reload page
                location.reload();
            }
        }).fail(function() {
            console.error('Failed to check video status');
        });
    }

    // Initialize status checking when DOM is ready
    $(document).ready(function() {
        setupStatusChecking();
    });

    // Public API
    return {
        init: init,
        getPlayer: getPlayer,
        destroyPlayer: destroyPlayer,
        refreshAllPlayers: refreshAllPlayers,
        checkVideoStatus: checkVideoStatus
    };
});