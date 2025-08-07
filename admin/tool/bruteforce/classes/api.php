<?php
namespace tool_bruteforce;

defined('MOODLE_INTERNAL') || die();

/**
 * Main API for bruteforce protection.
 *
 * Records failed logins and enforces blocks based on configured thresholds.
 * Also honours whitelist/blacklist entries and one-day blocks.
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

        if (self::is_whitelisted($ip)) {
            return; // Never count.
        }

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

        $soft = (int) get_config('tool_bruteforce', 'thresholdsoft');
        $hard = (int) get_config('tool_bruteforce', 'thresholdhard');
        $window = (int) get_config('tool_bruteforce', 'window');
        $softduration = (int) get_config('tool_bruteforce', 'durationsoft');
        $hardduration = (int) get_config('tool_bruteforce', 'durationhard');
        $onedayth = (int) get_config('tool_bruteforce', 'onedaythreshold');

        $within = ($now - $record->firstfail) <= $window;

        if ($onedayth > 0 && $record->count >= $onedayth) {
            self::block_ip_for_oneday($ip);
        } else if ($hard > 0 && $record->count >= $hard && $within) {
            self::block($userid, $ip, $hardduration);
        } else if ($soft > 0 && $record->count >= $soft && $within) {
            self::block($userid, $ip, $softduration);
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
        global $DB, $CFG;
        if (!empty($CFG->tool_bruteforce_rescue)) {
            return false;
        }
        if (self::is_whitelisted($ip)) {
            return false;
        }
        if (self::is_blacklisted($ip)) {
            return true;
        }
        $now = time();
        if ($DB->record_exists_select('tool_bruteforce_oneday', 'ip = :ip AND unblocktime > :now', ['ip' => $ip, 'now' => $now])) {
            return true;
        }
        return $DB->record_exists_select('tool_bruteforce_blocks', 'ip = :ip AND (userid IS NULL OR userid = :userid) AND unblocktime > :now', [
            'ip' => $ip,
            'userid' => $userid,
            'now' => $now,
        ]);
    }

    /**
     * Block an IP for one day.
     *
     * @param string $ip
     */
    protected static function block_ip_for_oneday(string $ip): void {
        global $DB;
        $duration = DAYSECS;
        $record = (object) [
            'ip' => $ip,
            'unblocktime' => time() + $duration,
            'timecreated' => time(),
        ];
        $DB->insert_record('tool_bruteforce_oneday', $record);
    }

    /**
     * Check if IP is on whitelist.
     *
     * @param string $ip
     * @return bool
     */
    public static function is_whitelisted(string $ip): bool {
        global $DB;
        $records = $DB->get_records_menu('tool_bruteforce_whitelist', null, '', 'id, ip');
        $list = implode(',', $records);
        return $list && address_in_subnet($ip, $list);
    }

    /**
     * Check if IP is on blacklist.
     *
     * @param string $ip
     * @return bool
     */
    public static function is_blacklisted(string $ip): bool {
        global $DB;
        $records = $DB->get_records_menu('tool_bruteforce_blacklist', null, '', 'id, ip');
        $list = implode(',', $records);
        return $list && address_in_subnet($ip, $list);
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