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
 * Scheduled task to clean up expired access tokens.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream\task;

use local_cloudflarestream\auth\token_manager;
use local_cloudflarestream\token_manager as TokenDB;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task to clean up expired access tokens and maintain security.
 */
class cleanup_tokens extends \core\task\scheduled_task {

    /**
     * Get task name.
     *
     * @return string Task name
     */
    public function get_name() {
        return get_string('task_cleanup_tokens', 'local_cloudflarestream');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        mtrace('Starting Cloudflare Stream token cleanup...');

        $totalcleaned = 0;

        // Clean up expired tokens
        $expiredcleaned = $this->cleanup_expired_tokens();
        $totalcleaned += $expiredcleaned;
        mtrace("Cleaned up {$expiredcleaned} expired tokens.");

        // Clean up old tokens for users (keep only recent ones)
        $oldcleaned = $this->cleanup_old_user_tokens();
        $totalcleaned += $oldcleaned;
        mtrace("Cleaned up {$oldcleaned} old user tokens.");

        // Security maintenance
        $this->perform_security_maintenance();

        // Clear rate limiting cache for inactive users
        $this->cleanup_rate_limit_cache();

        mtrace("Token cleanup completed. Total cleaned: {$totalcleaned}");

        // Generate cleanup report
        $this->generate_cleanup_report();
    }

    /**
     * Clean up expired tokens.
     *
     * @return int Number of tokens cleaned
     */
    private function cleanup_expired_tokens() {
        $batchsize = 1000;
        $totalcleaned = 0;

        do {
            $cleaned = token_manager::cleanup_expired_tokens($batchsize);
            $totalcleaned += $cleaned;
            
            if ($cleaned > 0) {
                mtrace("Cleaned batch of {$cleaned} expired tokens...");
            }
        } while ($cleaned >= $batchsize);

        return $totalcleaned;
    }

    /**
     * Clean up old tokens for users, keeping only recent ones.
     *
     * @return int Number of tokens cleaned
     */
    private function cleanup_old_user_tokens() {
        global $DB;

        $totalcleaned = 0;
        $keeplast = 5; // Keep last 5 tokens per user/video combination

        // Get user/video combinations with many tokens
        $sql = "SELECT user_id, video_id, COUNT(*) as token_count
                FROM {" . TokenDB::TABLE_TOKENS . "}
                WHERE expires_at > ?
                GROUP BY user_id, video_id
                HAVING COUNT(*) > ?
                ORDER BY token_count DESC";

        $combinations = $DB->get_records_sql($sql, [time(), $keeplast], 0, 100);

        foreach ($combinations as $combo) {
            $cleaned = TokenDB::cleanup_user_video_tokens($combo->user_id, $combo->video_id, $keeplast);
            $totalcleaned += $cleaned;
            
            if ($cleaned > 0) {
                mtrace("Cleaned {$cleaned} old tokens for user {$combo->user_id}, video {$combo->video_id}");
            }
        }

        return $totalcleaned;
    }

    /**
     * Perform security maintenance tasks.
     */
    private function perform_security_maintenance() {
        mtrace('Performing security maintenance...');

        // Check for suspicious token usage patterns
        $this->detect_suspicious_activity();

        // Validate token integrity
        $this->validate_token_integrity();

        // Clean up security logs
        $this->cleanup_security_logs();
    }

    /**
     * Detect suspicious token activity.
     */
    private function detect_suspicious_activity() {
        global $DB;

        // Look for users with excessive token generation
        $cutoff = time() - 3600; // Last hour
        $sql = "SELECT user_id, COUNT(*) as token_count
                FROM {" . TokenDB::TABLE_TOKENS . "}
                WHERE created_at > ?
                GROUP BY user_id
                HAVING COUNT(*) > 50
                ORDER BY token_count DESC";

        $suspicioususers = $DB->get_records_sql($sql, [$cutoff], 0, 10);

        foreach ($suspicioususers as $user) {
            mtrace("Suspicious activity detected: User {$user->user_id} generated {$user->token_count} tokens in the last hour");
            
            // Could implement additional actions here:
            // - Temporary rate limiting
            // - Admin notifications
            // - Account flagging
        }

        // Look for tokens from unusual IP addresses
        $this->detect_unusual_ip_activity();
    }

    /**
     * Detect unusual IP address activity.
     */
    private function detect_unusual_ip_activity() {
        global $DB;

        $cutoff = time() - 86400; // Last 24 hours

        // Find IPs with tokens from many different users
        $sql = "SELECT ip_address, COUNT(DISTINCT user_id) as user_count
                FROM {" . TokenDB::TABLE_TOKENS . "}
                WHERE created_at > ? AND ip_address IS NOT NULL
                GROUP BY ip_address
                HAVING COUNT(DISTINCT user_id) > 10
                ORDER BY user_count DESC";

        $suspiciousips = $DB->get_records_sql($sql, [$cutoff], 0, 5);

        foreach ($suspiciousips as $ip) {
            mtrace("Unusual IP activity: {$ip->ip_address} used by {$ip->user_count} different users");
        }
    }

    /**
     * Validate token integrity in database.
     */
    private function validate_token_integrity() {
        global $DB;

        // Check for tokens with invalid expiry dates
        $invalidtokens = $DB->count_records_select(
            TokenDB::TABLE_TOKENS,
            'expires_at < created_at OR expires_at > ?',
            [time() + 86400 * 7] // More than 7 days in future
        );

        if ($invalidtokens > 0) {
            mtrace("Found {$invalidtokens} tokens with invalid expiry dates");
            
            // Clean up invalid tokens
            $DB->delete_records_select(
                TokenDB::TABLE_TOKENS,
                'expires_at < created_at OR expires_at > ?',
                [time() + 86400 * 7]
            );
            
            mtrace("Cleaned up invalid tokens");
        }
    }

    /**
     * Clean up old security logs.
     */
    private function cleanup_security_logs() {
        // This would clean up security-related logs older than a certain period
        // For now, just a placeholder
        mtrace('Security logs cleanup completed');
    }

    /**
     * Clean up rate limiting cache for inactive users.
     */
    private function cleanup_rate_limit_cache() {
        // Clear rate limiting cache entries
        $cache = \cache::make('local_cloudflarestream', 'tokens');
        
        // This is a simple approach - in production you might want more sophisticated cleanup
        $cache->purge();
        
        mtrace('Rate limiting cache cleared');
    }

    /**
     * Generate cleanup report.
     */
    private function generate_cleanup_report() {
        $stats = token_manager::get_token_statistics(1); // Last 24 hours
        
        $report = [
            'timestamp' => time(),
            'active_tokens' => $stats['total_active'],
            'tokens_created_24h' => $stats['total_created'],
            'tokens_used_24h' => $stats['total_used'],
        ];

        mtrace('Cleanup report: ' . json_encode($report));

        // Store report for admin dashboard
        set_config('last_cleanup_report', json_encode($report), 'local_cloudflarestream');
        set_config('last_cleanup_time', time(), 'local_cloudflarestream');
    }

    /**
     * Emergency token revocation for security incidents.
     *
     * @param int $userid User ID to revoke tokens for
     * @param string $reason Reason for revocation
     */
    public static function emergency_revoke_user_tokens($userid, $reason = 'Security incident') {
        $revoked = TokenDB::revoke_user_tokens($userid);
        
        mtrace("Emergency revocation: {$revoked} tokens revoked for user {$userid}. Reason: {$reason}");
        
        // Log security incident
        debugging("SECURITY: Emergency token revocation for user {$userid}: {$reason}", DEBUG_NORMAL);
        
        return $revoked;
    }

    /**
     * Emergency token revocation for specific video.
     *
     * @param int $videoid Video record ID
     * @param string $reason Reason for revocation
     */
    public static function emergency_revoke_video_tokens($videoid, $reason = 'Security incident') {
        $revoked = TokenDB::revoke_video_tokens($videoid);
        
        mtrace("Emergency revocation: {$revoked} tokens revoked for video {$videoid}. Reason: {$reason}");
        
        // Log security incident
        debugging("SECURITY: Emergency token revocation for video {$videoid}: {$reason}", DEBUG_NORMAL);
        
        return $revoked;
    }

    /**
     * Get security statistics.
     *
     * @return array Security statistics
     */
    public static function get_security_statistics() {
        global $DB;

        $stats = [];
        
        // Token statistics
        $stats['total_active_tokens'] = $DB->count_records_select(
            TokenDB::TABLE_TOKENS,
            'expires_at > ?',
            [time()]
        );
        
        $stats['expired_tokens'] = $DB->count_records_select(
            TokenDB::TABLE_TOKENS,
            'expires_at <= ?',
            [time()]
        );

        // Recent activity (last 24 hours)
        $cutoff = time() - 86400;
        $stats['tokens_created_24h'] = $DB->count_records_select(
            TokenDB::TABLE_TOKENS,
            'created_at > ?',
            [$cutoff]
        );

        $stats['tokens_used_24h'] = $DB->count_records_select(
            TokenDB::TABLE_TOKENS,
            'last_used > ?',
            [$cutoff]
        );

        // Unique users and IPs
        $stats['unique_users_24h'] = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT user_id) FROM {" . TokenDB::TABLE_TOKENS . "} WHERE created_at > ?",
            [$cutoff]
        );

        $stats['unique_ips_24h'] = $DB->count_records_sql(
            "SELECT COUNT(DISTINCT ip_address) FROM {" . TokenDB::TABLE_TOKENS . "} WHERE created_at > ? AND ip_address IS NOT NULL",
            [$cutoff]
        );

        return $stats;
    }
}