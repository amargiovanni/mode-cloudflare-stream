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
 * Plugin strings are defined here.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Cloudflare Stream Integration';
$string['privacy:metadata'] = 'The Cloudflare Stream plugin stores video access tokens and usage logs to provide secure video streaming functionality.';

// API Configuration
$string['apiheading'] = 'Cloudflare API Configuration';
$string['apiheading_desc'] = 'Configure your Cloudflare Stream API credentials. You can find these in your Cloudflare dashboard under Stream settings.';
$string['api_token'] = 'API Token';
$string['api_token_desc'] = 'Your Cloudflare API token with Stream:Edit permissions. Keep this secure!';
$string['account_id'] = 'Account ID';
$string['account_id_desc'] = 'Your Cloudflare Account ID (found in the right sidebar of your Cloudflare dashboard)';
$string['zone_id'] = 'Zone ID (Optional)';
$string['zone_id_desc'] = 'Zone ID for custom domain configuration. Leave empty if using default Cloudflare Stream domain.';

// Upload Settings
$string['uploadheading'] = 'Upload Settings';
$string['uploadheading_desc'] = 'Configure video upload parameters and supported formats.';
$string['max_file_size'] = 'Maximum File Size (bytes)';
$string['max_file_size_desc'] = 'Maximum size for video files to be uploaded to Cloudflare Stream. Default: 500MB (524288000 bytes)';
$string['supported_formats'] = 'Supported Video Formats';
$string['supported_formats_desc'] = 'Comma-separated list of supported video file extensions (without dots). Example: mp4,mov,avi,mkv,webm';

// Player Settings
$string['playerheading'] = 'Player Settings';
$string['playerheading_desc'] = 'Configure the Cloudflare Stream player behavior and security settings.';
$string['token_expiry'] = 'Token Expiry Time (seconds)';
$string['token_expiry_desc'] = 'How long access tokens remain valid. Default: 3600 seconds (1 hour)';
$string['player_controls'] = 'Show Player Controls';
$string['player_controls_desc'] = 'Whether to show video player controls (play, pause, volume, etc.)';
$string['autoplay'] = 'Enable Autoplay';
$string['autoplay_desc'] = 'Whether videos should start playing automatically when loaded';

// Maintenance Settings
$string['maintenanceheading'] = 'Maintenance Settings';
$string['maintenanceheading_desc'] = 'Configure automatic cleanup and maintenance tasks.';
$string['cleanup_delay'] = 'File Cleanup Delay (seconds)';
$string['cleanup_delay_desc'] = 'How long to keep local video files after successful upload to Cloudflare. Default: 604800 seconds (7 days)';

// Error Messages
$string['error_api_connection'] = 'Failed to connect to Cloudflare API. Please check your credentials.';
$string['error_invalid_token'] = 'Invalid or expired access token.';
$string['error_upload_failed'] = 'Video upload to Cloudflare Stream failed.';
$string['error_video_not_found'] = 'Video not found on Cloudflare Stream.';
$string['error_insufficient_permissions'] = 'Insufficient permissions to access this video.';
$string['error_file_too_large'] = 'File size exceeds the maximum allowed limit.';
$string['error_unsupported_format'] = 'Video format not supported.';

// Success Messages
$string['success_upload_started'] = 'Video upload to Cloudflare Stream has started.';
$string['success_upload_completed'] = 'Video successfully uploaded to Cloudflare Stream.';
$string['success_config_saved'] = 'Configuration saved successfully.';
$string['success_api_connection'] = 'Successfully connected to Cloudflare API.';

// Status Messages
$string['status_pending'] = 'Pending upload';
$string['status_uploading'] = 'Uploading to Cloudflare';
$string['status_processing'] = 'Processing on Cloudflare';
$string['status_ready'] = 'Ready for streaming';
$string['status_error'] = 'Upload error';

// Admin Dashboard
$string['dashboard'] = 'Cloudflare Stream Dashboard';
$string['video_statistics'] = 'Video Statistics';
$string['total_videos'] = 'Total Videos';
$string['pending_uploads'] = 'Pending Uploads';
$string['failed_uploads'] = 'Failed Uploads';
$string['storage_used'] = 'Storage Used';

// Test Connection
$string['testheading'] = 'Connection Test';
$string['testheading_desc'] = 'Test your Cloudflare Stream API connection to verify credentials are working correctly.';
$string['test_connection'] = 'Test API Connection';
$string['test_connection_desc'] = 'Click the button below to test your Cloudflare Stream API connection.';
$string['test_connection_button'] = 'Test Connection';
$string['test_connection_success'] = 'Connection successful! Your Cloudflare Stream API credentials are working correctly.';
$string['test_connection_failed'] = 'Connection failed. Please check your API credentials.';
$string['test_connection_testing'] = 'Testing connection...';

// Notifications
$string['upload_completed_subject'] = 'Video upload completed';
$string['upload_completed_message'] = 'Your video "{$a->filename}" has been successfully uploaded to Cloudflare Stream in course "{$a->course}". It is now ready for streaming.';
$string['upload_completed_small'] = 'Video upload completed successfully';
$string['upload_failed_subject'] = 'Video upload failed';
$string['upload_failed_message'] = 'Your video "{$a->filename}" failed to upload to Cloudflare Stream in course "{$a->course}". Error: {$a->error}';
$string['upload_failed_small'] = 'Video upload failed';

// Security Settings
$string['securityheading'] = 'Security Settings';
$string['securityheading_desc'] = 'Configure security restrictions for video access and player embedding.';
$string['domain_restrictions'] = 'Enable Domain Restrictions';
$string['domain_restrictions_desc'] = 'Restrict video playback to specific domains only';
$string['allowed_domains'] = 'Allowed Domains';
$string['allowed_domains_desc'] = 'Comma-separated list of domains allowed to embed videos. Leave empty to allow all domains.';
$string['referrer_restrictions'] = 'Enable Referrer Restrictions';
$string['referrer_restrictions_desc'] = 'Restrict video access based on HTTP referrer';
$string['allowed_referrers'] = 'Allowed Referrers';
$string['allowed_referrers_desc'] = 'Comma-separated list of domains allowed as referrers. Leave empty to allow all referrers.';
$string['enable_fallback_player'] = 'Enable Fallback Player';
$string['enable_fallback_player_desc'] = 'Show HTML5 fallback player when Cloudflare Stream is unavailable';

// Tasks
$string['task_process_queue'] = 'Process Cloudflare Stream upload queue';
$string['task_sync_videos'] = 'Sync video status with Cloudflare Stream';
$string['task_cleanup_files'] = 'Clean up local video files';
$string['task_cleanup_tokens'] = 'Clean up expired access tokens';

// Privacy API
$string['privacy:metadata:videos'] = 'Information about videos uploaded to Cloudflare Stream';
$string['privacy:metadata:videos:user_id'] = 'The ID of the user who uploaded the video';
$string['privacy:metadata:videos:course_id'] = 'The ID of the course where the video was uploaded';
$string['privacy:metadata:videos:moodle_file_id'] = 'The ID of the original file in Moodle';
$string['privacy:metadata:videos:cloudflare_video_id'] = 'The unique identifier for the video on Cloudflare Stream';
$string['privacy:metadata:videos:status'] = 'The current processing status of the video';
$string['privacy:metadata:videos:upload_date'] = 'When the video was uploaded';
$string['privacy:metadata:videos:ready_date'] = 'When the video became ready for streaming';
$string['privacy:metadata:videos:file_size'] = 'The size of the video file in bytes';
$string['privacy:metadata:videos:metadata'] = 'Additional metadata about the video';
$string['privacy:metadata:videos:error_message'] = 'Error message if video processing failed';

$string['privacy:metadata:tokens'] = 'Access tokens used for secure video viewing';
$string['privacy:metadata:tokens:user_id'] = 'The ID of the user who requested access to the video';
$string['privacy:metadata:tokens:video_id'] = 'The ID of the video being accessed';
$string['privacy:metadata:tokens:token_hash'] = 'Hashed version of the access token';
$string['privacy:metadata:tokens:expires_at'] = 'When the access token expires';
$string['privacy:metadata:tokens:created_at'] = 'When the access token was created';
$string['privacy:metadata:tokens:last_used'] = 'When the access token was last used';
$string['privacy:metadata:tokens:ip_address'] = 'IP address from which the token was used';
$string['privacy:metadata:tokens:user_agent'] = 'Browser user agent string when token was used';

$string['privacy:metadata:cloudflare'] = 'Video content and metadata stored on Cloudflare Stream service';
$string['privacy:metadata:cloudflare:video_content'] = 'The actual video file content uploaded to Cloudflare Stream';
$string['privacy:metadata:cloudflare:video_metadata'] = 'Video metadata such as filename, duration, and encoding information';
$string['privacy:metadata:cloudflare:access_logs'] = 'Logs of when and how videos are accessed through Cloudflare Stream';

$string['privacy:path:videos'] = 'Videos';
$string['privacy:path:tokens'] = 'Access Tokens';
$string['privacy:path:all_videos'] = 'All Videos';
$string['privacy:path:all_tokens'] = 'All Access Tokens';