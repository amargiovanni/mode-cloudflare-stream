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
 * Notification manager for upload status updates.
 *
 * @package     local_cloudflarestream
 * @copyright   2025 Your Name <your@email.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_cloudflarestream;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages notifications for video upload status changes.
 */
class notification_manager {

    /**
     * Send upload completion notification.
     *
     * @param int $videoid Video record ID
     * @param int $userid User ID to notify
     */
    public static function notify_upload_completed($videoid, $userid) {
        global $DB;

        $video = video_manager::get_video($videoid);
        if (!$video) {
            return;
        }

        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user) {
            return;
        }

        // Create notification message
        $message = new \core\message\message();
        $message->component = 'local_cloudflarestream';
        $message->name = 'upload_completed';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = get_string('upload_completed_subject', 'local_cloudflarestream');
        $message->fullmessage = get_string('upload_completed_message', 'local_cloudflarestream', [
            'filename' => self::get_video_filename($video),
            'course' => self::get_course_name($video->course_id)
        ]);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = get_string('upload_completed_small', 'local_cloudflarestream');
        $message->notification = 1;

        message_send($message);
    }

    /**
     * Send upload failed notification.
     *
     * @param int $videoid Video record ID
     * @param int $userid User ID to notify
     * @param string $error Error message
     */
    public static function notify_upload_failed($videoid, $userid, $error) {
        global $DB;

        $video = video_manager::get_video($videoid);
        if (!$video) {
            return;
        }

        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user) {
            return;
        }

        // Create notification message
        $message = new \core\message\message();
        $message->component = 'local_cloudflarestream';
        $message->name = 'upload_failed';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = get_string('upload_failed_subject', 'local_cloudflarestream');
        $message->fullmessage = get_string('upload_failed_message', 'local_cloudflarestream', [
            'filename' => self::get_video_filename($video),
            'course' => self::get_course_name($video->course_id),
            'error' => $error
        ]);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = get_string('upload_failed_small', 'local_cloudflarestream');
        $message->notification = 1;

        message_send($message);
    }

    /**
     * Send admin notification for system errors.
     *
     * @param string $subject Notification subject
     * @param string $message Notification message
     */
    public static function notify_admin($subject, $message) {
        global $DB;

        // Get site administrators
        $admins = get_admins();
        
        foreach ($admins as $admin) {
            $notification = new \core\message\message();
            $notification->component = 'local_cloudflarestream';
            $notification->name = 'admin_notification';
            $notification->userfrom = \core_user::get_noreply_user();
            $notification->userto = $admin;
            $notification->subject = '[Cloudflare Stream] ' . $subject;
            $notification->fullmessage = $message;
            $notification->fullmessageformat = FORMAT_PLAIN;
            $notification->fullmessagehtml = '';
            $notification->smallmessage = $subject;
            $notification->notification = 1;

            message_send($notification);
        }
    }

    /**
     * Get video filename from metadata.
     *
     * @param \stdClass $video Video record
     * @return string Filename
     */
    private static function get_video_filename($video) {
        $metadata = json_decode($video->metadata ?: '{}', true);
        return $metadata['original_filename'] ?? 'Unknown file';
    }

    /**
     * Get course name.
     *
     * @param int $courseid Course ID
     * @return string Course name
     */
    private static function get_course_name($courseid) {
        global $DB;
        
        $course = $DB->get_record('course', ['id' => $courseid], 'fullname');
        return $course ? $course->fullname : 'Unknown course';
    }
}