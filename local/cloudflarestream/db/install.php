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
 * Post installation and migration code.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Custom code to be run on installing the plugin.
 */
function xmldb_local_cloudflarestream_install() {
    global $CFG;

    // Set default configuration values
    $defaults = [
        'max_file_size' => 524288000, // 500MB
        'supported_formats' => 'mp4,mov,avi,mkv,webm',
        'token_expiry' => 3600, // 1 hour
        'player_controls' => 1,
        'autoplay' => 0,
        'cleanup_delay' => 604800, // 7 days
    ];

    foreach ($defaults as $name => $value) {
        if (get_config('local_cloudflarestream', $name) === false) {
            set_config($name, $value, 'local_cloudflarestream');
        }
    }

    return true;
}