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
 * Token database manager class.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages access tokens in the database.
 */
class token_manager {

    /** @var string Table name for tokens */
    const TABLE_TOKENS = 'local_cloudflarestream_tokens';

    /**
     * Create a new token record.
     *
     * @param int $userid User ID
     * @param int $videoid Video record ID
     * @param string $tokenhash Hashed token
     * @param int $expiry Expiration timestamp
     * @param string $ipaddress IP address
     * @param string $useragent User agent string
     * @return int Token record ID
     */
    public static function create_token($userid, $videoid, $tokenhash, $expiry, $ipaddress = null, $useragent = null) {
        global $DB;

        $record = new \stdClass();
        $record->user_id = $userid;
        $record->video_id = $videoid;
        $record->token_hash = $tokenhash;
        $record->expires_at = $expiry;
        $record->created_at = time();
        $record->ip_address = $ipaddress;
        $record->user_agent = $useragent;

        return $DB->insert_record(self::TABLE_TOKENS, $record);
    }

    /**
     * Get token record by hash.
     *
     * @param string $tokenhash Token hash
     * @return \stdClass|false Token record or false if not found
     */
    public static function get_token($tokenhash) {
        global $DB;
        return $DB->get_record(self::TABLE_TOKENS, ['token_hash' => $tokenhash]);
    }

    /**
     * Validate token and check expiry.
     *
     * @param string $tokenhash Token hash
     * @return \stdClass|false Token record if valid, false otherwise
     */
    public static function validate_token($tokenhash) {
        global $DB;

        $token = self::get_token($tokenhash);
        if (!$token) {
            return false;
        }

        // Check if token has expired
        if ($token->expires_at < time()) {
            // Clean up expired token
            self::delete_token($token->id);
            return false;
        }

        // Update last used timestamp
        self::update_token_usage($token->id);

        return $token;
    }

    /**
     * Update token last used timestamp.
     *
     * @param int $tokenid Token record ID
     * @return bool Success
     */
    public static function update_token_usage($tokenid) {
        global $DB;

        $record = new \stdClass();
        $record->id = $tokenid;
        $record->last_used = time();

        return $DB->update_record(self::TABLE_TOKENS, $record);
    }

    /**
     * Get active tokens for user and video.
     *
     * @param int $userid User ID
     * @param int $videoid Video record ID
     * @return array Token records
     */
    public static function get_user_video_tokens($userid, $videoid) {
        global $DB;

        $sql = "SELECT * FROM {" . self::TABLE_TOKENS . "} 
                WHERE user_id = ? AND video_id = ? AND expires_at > ?
                ORDER BY created_at DESC";

        return $DB->get_records_sql($sql, [$userid, $videoid, time()]);
    }

    /**
     * Delete token record.
     *
     * @param int $tokenid Token record ID
     * @return bool Success
     */
    public static function delete_token($tokenid) {
        global $DB;
        return $DB->delete_records(self::TABLE_TOKENS, ['id' => $tokenid]);
    }

    /**
     * Delete tokens by hash.
     *
     * @param string $tokenhash Token hash
     * @return bool Success
     */
    public static function delete_token_by_hash($tokenhash) {
        global $DB;
        return $DB->delete_records(self::TABLE_TOKENS, ['token_hash' => $tokenhash]);
    }

    /**
     * Clean up expired tokens.
     *
     * @param int $batchsize Number of tokens to delete per batch
     * @return int Number of tokens deleted
     */
    public static function cleanup_expired_tokens($batchsize = 1000) {
        global $DB;

        $cutoff = time();
        return $DB->delete_records_select(self::TABLE_TOKENS, 'expires_at < ?', [$cutoff], 0, $batchsize);
    }

    /**
     * Clean up old tokens for a user/video combination.
     *
     * @param int $userid User ID
     * @param int $videoid Video record ID
     * @param int $keeplast Number of recent tokens to keep
     * @return int Number of tokens deleted
     */
    public static function cleanup_user_video_tokens($userid, $videoid, $keeplast = 5) {
        global $DB;

        // Get tokens ordered by creation date (newest first)
        $sql = "SELECT id FROM {" . self::TABLE_TOKENS . "} 
                WHERE user_id = ? AND video_id = ?
                ORDER BY created_at DESC";
        
        $tokens = $DB->get_records_sql($sql, [$userid, $videoid]);
        
        if (count($tokens) <= $keeplast) {
            return 0; // Nothing to clean up
        }

        // Get IDs of tokens to delete (skip the first $keeplast)
        $tokenstokeep = array_slice($tokens, 0, $keeplast, true);
        $tokenstodelete = array_diff_key($tokens, $tokenstokeep);
        
        if (empty($tokenstodelete)) {
            return 0;
        }

        $deleteids = array_keys($tokenstodelete);
        list($insql, $params) = $DB->get_in_or_equal($deleteids);
        
        return $DB->delete_records_select(self::TABLE_TOKENS, "id $insql", $params);
    }

    /**
     * Get token usage statistics.
     *
     * @param int $days Number of days to look back
     * @return array Statistics
     */
    public static function get_token_statistics($days = 30) {
        global $DB;

        $cutoff = time() - ($days * 24 * 3600);
        
        $stats = [];
        $stats['total_active'] = $DB->count_records_select(self::TABLE_TOKENS, 'expires_at > ?', [time()]);
        $stats['total_created'] = $DB->count_records_select(self::TABLE_TOKENS, 'created_at > ?', [$cutoff]);
        $stats['total_used'] = $DB->count_records_select(self::TABLE_TOKENS, 'last_used > ?', [$cutoff]);

        // Get most active users
        $sql = "SELECT user_id, COUNT(*) as token_count 
                FROM {" . self::TABLE_TOKENS . "} 
                WHERE created_at > ? 
                GROUP BY user_id 
                ORDER BY token_count DESC";
        
        $stats['top_users'] = $DB->get_records_sql($sql, [$cutoff], 0, 10);

        return $stats;
    }

    /**
     * Revoke all tokens for a user.
     *
     * @param int $userid User ID
     * @return int Number of tokens revoked
     */
    public static function revoke_user_tokens($userid) {
        global $DB;
        return $DB->delete_records(self::TABLE_TOKENS, ['user_id' => $userid]);
    }

    /**
     * Revoke all tokens for a video.
     *
     * @param int $videoid Video record ID
     * @return int Number of tokens revoked
     */
    public static function revoke_video_tokens($videoid) {
        global $DB;
        return $DB->delete_records(self::TABLE_TOKENS, ['video_id' => $videoid]);
    }
}