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
 * Plugin administration pages are defined here.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_cloudflarestream', get_string('pluginname', 'local_cloudflarestream'));

    // Include JavaScript for admin interface
    global $PAGE;
    $PAGE->requires->js_call_amd('local_cloudflarestream/admin', 'init');

    // Cloudflare API Configuration section
    $settings->add(new admin_setting_heading(
        'local_cloudflarestream/apiheading',
        get_string('apiheading', 'local_cloudflarestream'),
        get_string('apiheading_desc', 'local_cloudflarestream')
    ));

    // API Token
    $settings->add(new admin_setting_configpasswordunmask(
        'local_cloudflarestream/api_token',
        get_string('api_token', 'local_cloudflarestream'),
        get_string('api_token_desc', 'local_cloudflarestream'),
        ''
    ));

    // Account ID
    $settings->add(new admin_setting_configtext(
        'local_cloudflarestream/account_id',
        get_string('account_id', 'local_cloudflarestream'),
        get_string('account_id_desc', 'local_cloudflarestream'),
        '',
        PARAM_ALPHANUM
    ));

    // Zone ID (optional)
    $settings->add(new admin_setting_configtext(
        'local_cloudflarestream/zone_id',
        get_string('zone_id', 'local_cloudflarestream'),
        get_string('zone_id_desc', 'local_cloudflarestream'),
        '',
        PARAM_ALPHANUM
    ));

    // Upload Settings section
    $settings->add(new admin_setting_heading(
        'local_cloudflarestream/uploadheading',
        get_string('uploadheading', 'local_cloudflarestream'),
        get_string('uploadheading_desc', 'local_cloudflarestream')
    ));

    // Maximum file size
    $settings->add(new admin_setting_configtext(
        'local_cloudflarestream/max_file_size',
        get_string('max_file_size', 'local_cloudflarestream'),
        get_string('max_file_size_desc', 'local_cloudflarestream'),
        '524288000', // 500MB in bytes
        PARAM_INT
    ));

    // Supported formats
    $settings->add(new admin_setting_configtextarea(
        'local_cloudflarestream/supported_formats',
        get_string('supported_formats', 'local_cloudflarestream'),
        get_string('supported_formats_desc', 'local_cloudflarestream'),
        'mp4,mov,avi,mkv,webm',
        PARAM_TEXT
    ));

    // Player Settings section
    $settings->add(new admin_setting_heading(
        'local_cloudflarestream/playerheading',
        get_string('playerheading', 'local_cloudflarestream'),
        get_string('playerheading_desc', 'local_cloudflarestream')
    ));

    // Token expiry time
    $settings->add(new admin_setting_configtext(
        'local_cloudflarestream/token_expiry',
        get_string('token_expiry', 'local_cloudflarestream'),
        get_string('token_expiry_desc', 'local_cloudflarestream'),
        '3600', // 1 hour
        PARAM_INT
    ));

    // Player controls
    $settings->add(new admin_setting_configcheckbox(
        'local_cloudflarestream/player_controls',
        get_string('player_controls', 'local_cloudflarestream'),
        get_string('player_controls_desc', 'local_cloudflarestream'),
        1
    ));

    // Autoplay
    $settings->add(new admin_setting_configcheckbox(
        'local_cloudflarestream/autoplay',
        get_string('autoplay', 'local_cloudflarestream'),
        get_string('autoplay_desc', 'local_cloudflarestream'),
        0
    ));

    // Maintenance Settings section
    $settings->add(new admin_setting_heading(
        'local_cloudflarestream/maintenanceheading',
        get_string('maintenanceheading', 'local_cloudflarestream'),
        get_string('maintenanceheading_desc', 'local_cloudflarestream')
    ));

    // Cleanup delay
    $settings->add(new admin_setting_configtext(
        'local_cloudflarestream/cleanup_delay',
        get_string('cleanup_delay', 'local_cloudflarestream'),
        get_string('cleanup_delay_desc', 'local_cloudflarestream'),
        '604800', // 7 days in seconds
        PARAM_INT
    ));

    // Security Settings section
    $settings->add(new admin_setting_heading(
        'local_cloudflarestream/securityheading',
        get_string('securityheading', 'local_cloudflarestream'),
        get_string('securityheading_desc', 'local_cloudflarestream')
    ));

    // Domain restrictions
    $settings->add(new admin_setting_configcheckbox(
        'local_cloudflarestream/domain_restrictions',
        get_string('domain_restrictions', 'local_cloudflarestream'),
        get_string('domain_restrictions_desc', 'local_cloudflarestream'),
        0
    ));

    // Allowed domains
    $settings->add(new admin_setting_configtextarea(
        'local_cloudflarestream/allowed_domains',
        get_string('allowed_domains', 'local_cloudflarestream'),
        get_string('allowed_domains_desc', 'local_cloudflarestream'),
        '',
        PARAM_TEXT
    ));

    // Referrer restrictions
    $settings->add(new admin_setting_configcheckbox(
        'local_cloudflarestream/referrer_restrictions',
        get_string('referrer_restrictions', 'local_cloudflarestream'),
        get_string('referrer_restrictions_desc', 'local_cloudflarestream'),
        0
    ));

    // Allowed referrers
    $settings->add(new admin_setting_configtextarea(
        'local_cloudflarestream/allowed_referrers',
        get_string('allowed_referrers', 'local_cloudflarestream'),
        get_string('allowed_referrers_desc', 'local_cloudflarestream'),
        '',
        PARAM_TEXT
    ));

    // Enable fallback player
    $settings->add(new admin_setting_configcheckbox(
        'local_cloudflarestream/enable_fallback_player',
        get_string('enable_fallback_player', 'local_cloudflarestream'),
        get_string('enable_fallback_player_desc', 'local_cloudflarestream'),
        1
    ));

    // Test connection section
    $settings->add(new admin_setting_heading(
        'local_cloudflarestream/testheading',
        get_string('testheading', 'local_cloudflarestream'),
        get_string('testheading_desc', 'local_cloudflarestream')
    ));

    // Add test connection button (will be handled by JavaScript)
    $settings->add(new admin_setting_description(
        'local_cloudflarestream/test_connection',
        get_string('test_connection', 'local_cloudflarestream'),
        get_string('test_connection_desc', 'local_cloudflarestream') . 
        '<div id="cloudflarestream-test-result" style="margin-top: 10px;"></div>' .
        '<button type="button" id="cloudflarestream-test-btn" class="btn btn-secondary">' . 
        get_string('test_connection_button', 'local_cloudflarestream') . '</button>'
    ));

    $ADMIN->add('localplugins', $settings);
}