<?php
namespace tool_bruteforce;

defined('MOODLE_INTERNAL') || die();

/**
 * Main API for bruteforce protection.
 *
 * This is a very small subset of the desired functionality. It records failed
 * login attempts and blocks combinations exceeding the configured threshold.
 */
class api {
    /**
     * Record a failed login attempt.
     *
     * @param int|null $userid User ID if known.
     * @param string $ip IP address.
     */
    public static function failed(?int $userid, string $ip): void {
        global $DB;

        $record = $DB->get_record('tool_bruteforce_attempts', ['userid' => $userid, 'ip' => $ip]);
        $now = time();
        if ($record) {
            $record->count++;
            $record->lastfail = $now;
            $DB->update_record('tool_bruteforce_attempts', $record);
        } else {
            $record = (object) [
                'userid' => $userid,
                'ip' => $ip,
                'count' => 1,
                'firstfail' => $now,
                'lastfail' => $now,
            ];
            $DB->insert_record('tool_bruteforce_attempts', $record);
        }

        $threshold = (int) get_config('tool_bruteforce', 'threshold');
        $window = (int) get_config('tool_bruteforce', 'window');

        if ($threshold > 0 && $record->count >= $threshold && ($now - $record->firstfail) <= $window) {
            self::block($userid, $ip, $window);
        }
    }

    /**
     * Record a successful login and reset counters.
     *
     * @param int $userid User ID.
     * @param string $ip IP address.
     */
    public static function success(int $userid, string $ip): void {
        global $DB;
        $DB->delete_records('tool_bruteforce_attempts', ['userid' => $userid, 'ip' => $ip]);
    }

    /**
     * Determine if given user/IP is blocked.
     *
     * @param int|null $userid
     * @param string $ip
     * @return bool
     */
    public static function is_blocked(?int $userid, string $ip): bool {
        global $DB;
        $now = time();
        return $DB->record_exists_select('tool_bruteforce_blocks', 'ip = :ip AND (userid IS NULL OR userid = :userid) AND unblocktime > :now', [
            'ip' => $ip,
            'userid' => $userid,
            'now' => $now,
        ]);
    }

    /**
     * Create a block entry for given user/IP.
     *
     * @param int|null $userid
     * @param string $ip
     * @param int $duration Duration in seconds.
     */
    public static function block(?int $userid, string $ip, int $duration): void {
        global $DB;
        $record = (object) [
            'userid' => $userid,
            'ip' => $ip,
            'unblocktime' => time() + $duration,
            'timecreated' => time(),
        ];
        $DB->insert_record('tool_bruteforce_blocks', $record);
    }
}
