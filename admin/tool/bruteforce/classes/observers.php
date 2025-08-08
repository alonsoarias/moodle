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
        $username = $event->other['username'] ?? null;
        api::failed($userid, $ip, $username);
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

    /**
     * Revoke freshly created tokens if the requester is blocked.
     *
     * @param \core\event\webservice_token_created $event
     */
    public static function webservice_token_created(\core\event\webservice_token_created $event): void {
        $ip = getremoteaddr(null);
        $userid = $event->relateduserid;
        if (api::is_blocked($userid, $ip)) {
            api::revoke_token($event->objectid, $userid, $ip);
        }
    }
}
