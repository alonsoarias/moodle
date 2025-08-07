<?php
namespace tool_bruteforce;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observers for the bruteforce tool.
 */
class observers {
    /**
     * Handle failed login event.
     *
     * @param \core\event\user_login_failed $event
     */
    public static function user_login_failed(\core\event\user_login_failed $event): void {
        $ip = getremoteaddr(null);
        $userid = $event->userid ?: null;
        api::failed($userid, $ip);
    }

    /**
     * Handle successful login event.
     *
     * @param \core\event\user_loggedin $event
     */
    public static function user_loggedin(\core\event\user_loggedin $event): void {
        $ip = getremoteaddr(null);
        $userid = $event->userid;
        api::success($userid, $ip);
    }
}
