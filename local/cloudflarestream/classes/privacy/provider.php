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
 * Privacy provider for Cloudflare Stream plugin.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\deletion_criteria;
use core_privacy\local\request\helper;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\local\request\transform;
use local_cloudflarestream\video_manager;
use local_cloudflarestream\auth\token_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy provider implementation for GDPR compliance.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * Get metadata about user data stored by this plugin.
     *
     * @param collection $collection The collection to add metadata to
     * @return collection The updated collection
     */
    public static function get_metadata(collection $collection): collection {
        // Video records table
        $collection->add_database_table(
            'local_cloudflarestream_videos',
            [
                'user_id' => 'privacy:metadata:videos:user_id',
                'course_id' => 'privacy:metadata:videos:course_id',
                'moodle_file_id' => 'privacy:metadata:videos:moodle_file_id',
                'cloudflare_video_id' => 'privacy:metadata:videos:cloudflare_video_id',
                'status' => 'privacy:metadata:videos:status',
                'upload_date' => 'privacy:metadata:videos:upload_date',
                'ready_date' => 'privacy:metadata:videos:ready_date',
                'file_size' => 'privacy:metadata:videos:file_size',
                'metadata' => 'privacy:metadata:videos:metadata',
                'error_message' => 'privacy:metadata:videos:error_message'
            ],
            'privacy:metadata:videos'
        );

        // Access tokens table
        $collection->add_database_table(
            'local_cloudflarestream_tokens',
            [
                'user_id' => 'privacy:metadata:tokens:user_id',
                'video_id' => 'privacy:metadata:tokens:video_id',
                'token_hash' => 'privacy:metadata:tokens:token_hash',
                'expires_at' => 'privacy:metadata:tokens:expires_at',
                'created_at' => 'privacy:metadata:tokens:created_at',
                'last_used' => 'privacy:metadata:tokens:last_used',
                'ip_address' => 'privacy:metadata:tokens:ip_address',
                'user_agent' => 'privacy:metadata:tokens:user_agent'
            ],
            'privacy:metadata:tokens'
        );

        // External service - Cloudflare Stream
        $collection->add_external_location_link(
            'cloudflare_stream',
            [
                'video_content' => 'privacy:metadata:cloudflare:video_content',
                'video_metadata' => 'privacy:metadata:cloudflare:video_metadata',
                'access_logs' => 'privacy:metadata:cloudflare:access_logs'
            ],
            'privacy:metadata:cloudflare'
        );

        return $collection;
    }

    /**
     * Get contexts that contain user information for the specified user.
     *
     * @param int $userid The user ID
     * @return contextlist The list of contexts containing user info
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        
        $contextlist = new contextlist();

        // Get contexts where user has uploaded videos
        $sql = "SELECT DISTINCT c.id
                FROM {context} c
                INNER JOIN {course} co ON co.id = c.instanceid AND c.contextlevel = :contextlevel
                INNER JOIN {" . video_manager::TABLE_VIDEOS . "} v ON v.course_id = co.id
                WHERE v.user_id = :userid";

        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid
        ]);

        // Also add system context if user has any videos or tokens
        $sql = "SELECT COUNT(*)
                FROM {" . video_manager::TABLE_VIDEOS . "} v
                WHERE v.user_id = :userid";

        $videocount = $DB->count_records_sql($sql, ['userid' => $userid]);

        $sql = "SELECT COUNT(*)
                FROM {" . token_manager::TABLE_TOKENS . "} t
                WHERE t.user_id = :userid";

        $tokencount = $DB->count_records_sql($sql, ['userid' => $userid]);

        if ($videocount > 0 || $tokencount > 0) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    /**
     * Get users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if ($context->contextlevel == CONTEXT_COURSE) {
            // Get users who have uploaded videos in this course
            $sql = "SELECT v.user_id
                    FROM {" . video_manager::TABLE_VIDEOS . "} v
                    WHERE v.course_id = :courseid";

            $userlist->add_from_sql('user_id', $sql, ['courseid' => $context->instanceid]);

            // Get users who have access tokens for videos in this course
            $sql = "SELECT t.user_id
                    FROM {" . token_manager::TABLE_TOKENS . "} t
                    INNER JOIN {" . video_manager::TABLE_VIDEOS . "} v ON v.id = t.video_id
                    WHERE v.course_id = :courseid";

            $userlist->add_from_sql('user_id', $sql, ['courseid' => $context->instanceid]);

        } else if ($context->contextlevel == CONTEXT_SYSTEM) {
            // Get all users with video data
            $sql = "SELECT v.user_id
                    FROM {" . video_manager::TABLE_VIDEOS . "} v";

            $userlist->add_from_sql('user_id', $sql, []);

            // Get all users with token data
            $sql = "SELECT t.user_id
                    FROM {" . token_manager::TABLE_TOKENS . "} t";

            $userlist->add_from_sql('user_id', $sql, []);
        }
    }

    /**
     * Export personal data for the given approved_contextlist.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_COURSE) {
                self::export_course_data($context, $userid);
            } else if ($context->contextlevel == CONTEXT_SYSTEM) {
                self::export_system_data($context, $userid);
            }
        }
    }

    /**
     * Export user data for a specific course context.
     *
     * @param \context $context The course context
     * @param int $userid The user ID
     */
    private static function export_course_data(\context $context, int $userid) {
        global $DB;

        // Export video data for this course
        $sql = "SELECT v.*, c.fullname as course_name
                FROM {" . video_manager::TABLE_VIDEOS . "} v
                INNER JOIN {course} c ON c.id = v.course_id
                WHERE v.course_id = :courseid AND v.user_id = :userid
                ORDER BY v.upload_date DESC";

        $videos = $DB->get_records_sql($sql, [
            'courseid' => $context->instanceid,
            'userid' => $userid
        ]);

        if (!empty($videos)) {
            $videodata = [];
            foreach ($videos as $video) {
                $metadata = json_decode($video->metadata ?: '{}', true);
                $videodata[] = [
                    'filename' => $metadata['original_filename'] ?? 'Unknown',
                    'status' => $video->status,
                    'upload_date' => transform::datetime($video->upload_date),
                    'ready_date' => $video->ready_date ? transform::datetime($video->ready_date) : null,
                    'file_size' => $video->file_size,
                    'cloudflare_video_id' => $video->cloudflare_video_id,
                    'error_message' => $video->error_message
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('privacy:path:videos', 'local_cloudflarestream')],
                (object) ['videos' => $videodata]
            );
        }

        // Export access token data for videos in this course
        $sql = "SELECT t.*, v.cloudflare_video_id
                FROM {" . token_manager::TABLE_TOKENS . "} t
                INNER JOIN {" . video_manager::TABLE_VIDEOS . "} v ON v.id = t.video_id
                WHERE v.course_id = :courseid AND t.user_id = :userid
                ORDER BY t.created_at DESC";

        $tokens = $DB->get_records_sql($sql, [
            'courseid' => $context->instanceid,
            'userid' => $userid
        ]);

        if (!empty($tokens)) {
            $tokendata = [];
            foreach ($tokens as $token) {
                $tokendata[] = [
                    'video_id' => $token->cloudflare_video_id,
                    'created_at' => transform::datetime($token->created_at),
                    'expires_at' => transform::datetime($token->expires_at),
                    'last_used' => $token->last_used ? transform::datetime($token->last_used) : null,
                    'ip_address' => $token->ip_address,
                    'user_agent' => $token->user_agent
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('privacy:path:tokens', 'local_cloudflarestream')],
                (object) ['access_tokens' => $tokendata]
            );
        }
    }

    /**
     * Export user data for system context.
     *
     * @param \context $context The system context
     * @param int $userid The user ID
     */
    private static function export_system_data(\context $context, int $userid) {
        global $DB;

        // Export all video data for the user
        $sql = "SELECT v.*, c.fullname as course_name
                FROM {" . video_manager::TABLE_VIDEOS . "} v
                LEFT JOIN {course} c ON c.id = v.course_id
                WHERE v.user_id = :userid
                ORDER BY v.upload_date DESC";

        $videos = $DB->get_records_sql($sql, ['userid' => $userid]);

        if (!empty($videos)) {
            $videodata = [];
            foreach ($videos as $video) {
                $metadata = json_decode($video->metadata ?: '{}', true);
                $videodata[] = [
                    'course_name' => $video->course_name ?? 'Unknown',
                    'filename' => $metadata['original_filename'] ?? 'Unknown',
                    'status' => $video->status,
                    'upload_date' => transform::datetime($video->upload_date),
                    'ready_date' => $video->ready_date ? transform::datetime($video->ready_date) : null,
                    'file_size' => $video->file_size,
                    'cloudflare_video_id' => $video->cloudflare_video_id,
                    'error_message' => $video->error_message
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('privacy:path:all_videos', 'local_cloudflarestream')],
                (object) ['all_videos' => $videodata]
            );
        }

        // Export all access token data for the user
        $sql = "SELECT t.*, v.cloudflare_video_id, c.fullname as course_name
                FROM {" . token_manager::TABLE_TOKENS . "} t
                INNER JOIN {" . video_manager::TABLE_VIDEOS . "} v ON v.id = t.video_id
                LEFT JOIN {course} c ON c.id = v.course_id
                WHERE t.user_id = :userid
                ORDER BY t.created_at DESC";

        $tokens = $DB->get_records_sql($sql, ['userid' => $userid]);

        if (!empty($tokens)) {
            $tokendata = [];
            foreach ($tokens as $token) {
                $tokendata[] = [
                    'course_name' => $token->course_name ?? 'Unknown',
                    'video_id' => $token->cloudflare_video_id,
                    'created_at' => transform::datetime($token->created_at),
                    'expires_at' => transform::datetime($token->expires_at),
                    'last_used' => $token->last_used ? transform::datetime($token->last_used) : null,
                    'ip_address' => $token->ip_address,
                    'user_agent' => $token->user_agent
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('privacy:path:all_tokens', 'local_cloudflarestream')],
                (object) ['all_access_tokens' => $tokendata]
            );
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context The specific context to delete data for
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context->contextlevel == CONTEXT_COURSE) {
            // Delete all video and token data for this course
            $coursevideos = $DB->get_records(video_manager::TABLE_VIDEOS, ['course_id' => $context->instanceid]);
            
            foreach ($coursevideos as $video) {
                self::delete_video_data($video->id);
            }

        } else if ($context->contextlevel == CONTEXT_SYSTEM) {
            // Delete all video and token data
            $allvideos = $DB->get_records(video_manager::TABLE_VIDEOS);
            
            foreach ($allvideos as $video) {
                self::delete_video_data($video->id);
            }
        }
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_COURSE) {
                // Delete user's video data for this course
                $videos = $DB->get_records(video_manager::TABLE_VIDEOS, [
                    'user_id' => $userid,
                    'course_id' => $context->instanceid
                ]);

                foreach ($videos as $video) {
                    self::delete_video_data($video->id, $userid);
                }

            } else if ($context->contextlevel == CONTEXT_SYSTEM) {
                // Delete all user's video data
                $videos = $DB->get_records(video_manager::TABLE_VIDEOS, ['user_id' => $userid]);

                foreach ($videos as $video) {
                    self::delete_video_data($video->id, $userid);
                }
            }
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        $userids = $userlist->get_userids();

        if (empty($userids)) {
            return;
        }

        list($usersql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        if ($context->contextlevel == CONTEXT_COURSE) {
            // Delete video data for specified users in this course
            $sql = "SELECT id FROM {" . video_manager::TABLE_VIDEOS . "}
                    WHERE user_id $usersql AND course_id = :courseid";
            $params = array_merge($userparams, ['courseid' => $context->instanceid]);

        } else if ($context->contextlevel == CONTEXT_SYSTEM) {
            // Delete video data for specified users
            $sql = "SELECT id FROM {" . video_manager::TABLE_VIDEOS . "}
                    WHERE user_id $usersql";
            $params = $userparams;
        } else {
            return;
        }

        $videos = $DB->get_records_sql($sql, $params);
        foreach ($videos as $video) {
            self::delete_video_data($video->id);
        }
    }

    /**
     * Delete all data associated with a video.
     *
     * @param int $videoid Video ID
     * @param int|null $userid Optional user ID to restrict deletion
     */
    private static function delete_video_data(int $videoid, int $userid = null) {
        global $DB;

        // Get video record
        $video = $DB->get_record(video_manager::TABLE_VIDEOS, ['id' => $videoid]);
        if (!$video) {
            return;
        }

        // If userid is specified, only delete if it matches
        if ($userid !== null && $video->user_id != $userid) {
            return;
        }

        // Delete from Cloudflare if video exists there
        if ($video->cloudflare_video_id) {
            $streammanager = \local_cloudflarestream\api\stream_manager::get_instance();
            if ($streammanager) {
                $streammanager->delete_video($video->cloudflare_video_id);
            }
        }

        // Delete access tokens
        $DB->delete_records(token_manager::TABLE_TOKENS, ['video_id' => $videoid]);

        // Delete from queue if present
        $DB->delete_records(video_manager::TABLE_QUEUE, ['video_id' => $videoid]);

        // Delete video record
        $DB->delete_records(video_manager::TABLE_VIDEOS, ['id' => $videoid]);

        // Delete associated Moodle file if it exists
        if ($video->moodle_file_id) {
            $fs = get_file_storage();
            $file = $fs->get_file_by_id($video->moodle_file_id);
            if ($file) {
                $file->delete();
            }
        }
    }
}