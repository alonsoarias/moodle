<?php
namespace tool_bruteforce;

defined('MOODLE_INTERNAL') || die();

/**
 * Main API for bruteforce protection supporting IP, user and user+IP axes.
 */
class api {
    /** Record a failed login attempt. */
    public static function failed(?int $userid, string $ip, ?string $username = null): void {
        global $DB;

        $username = $username !== null ? core_text::strtolower($username) : null;
        if (self::is_whitelisted($ip) || self::is_user_whitelisted($userid, $username)) {
            return;
        }

        // Coalescing identical attempts.
        $coalesce = (int) get_config('tool_bruteforce', 'coalescewindow');
        if ($coalesce > 0) {
            $cache = \cache::make('tool_bruteforce', 'coalesce');
            $key = sha1(($userid ?? $username ?? '') . '|' . $ip);
            if ($cache->get($key)) {
                return;
            }
            $cache->set($key, 1, $coalesce);
        }

        $now = time();
        $window = (int) get_config('tool_bruteforce', 'window');

        // Record attempts for the three axes.
        self::record_attempt(null, null, $ip, $now, $window);
        if ($userid || $username) {
            self::record_attempt($userid, $username, null, $now, $window);
            self::record_attempt($userid, $username, $ip, $now, $window);
        }

        // Enforce thresholds.
        $iprec = self::get_attempt(null, null, $ip);
        self::enforce_threshold('ip', $iprec->count, null, null, $ip);

        if ($userid || $username) {
            $userrec = self::get_attempt($userid, $username, null);
            $pairrec = self::get_attempt($userid, $username, $ip);
            self::enforce_threshold('user', $userrec->count, $userid, $username, null);
            self::enforce_threshold('pair', $pairrec->count, $userid, $username, $ip);
        }

        $onedayth = (int) get_config('tool_bruteforce', 'onedaythreshold');
        if ($onedayth > 0 && $iprec->count >= $onedayth) {
            self::block_ip_for_oneday($ip);
        }
    }

    /** Record a successful login. */
    public static function success(int $userid, string $ip): void {
        global $DB;
        // Remove username-based attempts for this user.
        $username = self::resolve_username($userid, null);
        $DB->delete_records('tool_bruteforce_attempts', ['userid' => $userid]);
        if ($username) {
            $DB->delete_records('tool_bruteforce_attempts', ['username' => $username]);
        }
        // Remove pair attempts.
        $DB->delete_records('tool_bruteforce_attempts', ['userid' => $userid, 'ip' => $ip]);
    }

    /** Determine if a request should be blocked. */
    public static function is_blocked(?int $userid, string $ip, ?string $username = null): bool {
        global $DB, $CFG;

        if (!empty($CFG->tool_bruteforce_rescue)) {
            return false;
        }

        $username = $username !== null ? core_text::strtolower($username) : null;

        // Precedence chain.
        if (self::is_whitelisted($ip)) {
            return false;
        }
        if (self::is_blacklisted($ip)) {
            return true;
        }
        if (self::is_user_whitelisted($userid, $username)) {
            return false;
        }
        if (self::is_user_blacklisted($userid, $username)) {
            return true;
        }

        $cache = \cache::make('tool_bruteforce', 'isblocked');
        $key = ($userid ?? 0) . '|' . ($username ?? '') . '|' . $ip;
        $cached = $cache->get($key);
        if ($cached !== false) {
            return (bool)$cached;
        }

        $now = time();
        $blocked = false;
        if ($DB->record_exists_select('tool_bruteforce_oneday', 'ip = :ip AND unblocktime > :now', ['ip' => $ip, 'now' => $now])) {
            $blocked = true;
        } else if ($userid || $username) {
            $params = ['ip' => $ip, 'now' => $now];
            $sql = 'unblocktime > :now AND ip = :ip';
            if ($userid) {
                $sql .= ' AND userid = :userid';
                $params['userid'] = $userid;
            } else {
                $sql .= ' AND username = :username';
                $params['username'] = $username;
            }
            if ($DB->record_exists_select('tool_bruteforce_blocks', $sql, $params)) {
                $blocked = true; // Pair block.
            } else {
                $params = ['now' => $now];
                $sql = 'unblocktime > :now AND ip IS NULL';
                if ($userid) {
                    $sql .= ' AND userid = :userid';
                    $params['userid'] = $userid;
                } else {
                    $sql .= ' AND username = :username';
                    $params['username'] = $username;
                }
                if ($DB->record_exists_select('tool_bruteforce_blocks', $sql, $params)) {
                    $blocked = true; // User block.
                }
            }
        } else {
            $blocked = $DB->record_exists_select('tool_bruteforce_blocks',
                'ip = :ip AND userid IS NULL AND username IS NULL AND unblocktime > :now',
                ['ip' => $ip, 'now' => $now]);
        }

        $cache->set($key, $blocked, 60);
        return $blocked;
    }

    /** Check core allowed IP list. */
    public static function is_whitelisted(string $ip): bool {
        global $CFG;
        $cache = \cache::make('tool_bruteforce', 'corelists');
        $lists = $cache->get('lists');
        if ($lists === false) {
            $lists = ['allowedip' => $CFG->allowedip, 'blockedip' => $CFG->blockedip];
            $cache->set('lists', $lists);
        }
        return !empty($lists['allowedip']) && address_in_subnet($ip, $lists['allowedip']);
    }

    /** Check core blocked IP list. */
    public static function is_blacklisted(string $ip): bool {
        global $CFG;
        $cache = \cache::make('tool_bruteforce', 'corelists');
        $lists = $cache->get('lists');
        if ($lists === false) {
            $lists = ['allowedip' => $CFG->allowedip, 'blockedip' => $CFG->blockedip];
            $cache->set('lists', $lists);
        }
        return !empty($lists['blockedip']) && address_in_subnet($ip, $lists['blockedip']);
    }

    /** Check user whitelist. */
    public static function is_user_whitelisted(?int $userid, ?string $username): bool {
        $username = self::resolve_username($userid, $username);
        if (!$username) {
            return false;
        }
        $lists = self::get_user_lists();
        return in_array($username, $lists['whitelist']);
    }

    /** Check user blacklist. */
    public static function is_user_blacklisted(?int $userid, ?string $username): bool {
        $username = self::resolve_username($userid, $username);
        if (!$username) {
            return false;
        }
        $lists = self::get_user_lists();
        return in_array($username, $lists['blacklist']);
    }

    /** Internal: load user lists from cache. */
    protected static function get_user_lists(): array {
        $cache = \cache::make('tool_bruteforce', 'userlists');
        $lists = $cache->get('lists');
        if ($lists !== false) {
            return $lists;
        }
        global $DB;
        $lists = [
            'whitelist' => array_map('strtolower', $DB->get_fieldset_select('tool_bruteforce_userwhitelist', 'username', '1=1')), 
            'blacklist' => array_map('strtolower', $DB->get_fieldset_select('tool_bruteforce_userblacklist', 'username', '1=1')),
        ];
        $cache->set('lists', $lists, 300);
        return $lists;
    }

    /** Block an IP for one day. */
    protected static function block_ip_for_oneday(string $ip): void {
        global $DB;
        $duration = (int) get_config('tool_bruteforce', 'onedayduration');
        if ($duration <= 0) {
            $duration = DAYSECS;
        }
        $record = (object) [
            'ip' => $ip,
            'unblocktime' => time() + $duration,
            'timecreated' => time(),
        ];
        $DB->insert_record('tool_bruteforce_oneday', $record);
        \cache::make('tool_bruteforce', 'isblocked')->purge();
        self::log('oneday', $ip, null, '', 'threshold', $duration);
    }

    /** Generic block helper. */
    public static function block(?int $userid, ?string $username, ?string $ip, int $duration, string $reason = ''): void {
        global $DB;
        $record = (object) [
            'userid' => $userid,
            'username' => $username ? core_text::strtolower($username) : null,
            'ip' => $ip,
            'unblocktime' => time() + $duration,
            'timecreated' => time(),
        ];
        $DB->insert_record('tool_bruteforce_blocks', $record);
        \cache::make('tool_bruteforce', 'isblocked')->purge();
        self::log('block', $ip ?? '', $userid, $record->username ?? '', $reason, $duration);
    }

    /** Revoke a recently created web service token. */
    public static function revoke_token(int $tokenid, int $userid, string $ip): void {
        global $DB;
        $window = (int) get_config('tool_bruteforce', 'tokenrevokewindow');
        if ($window <= 0) {
            return;
        }
        $token = $DB->get_record('external_tokens', ['id' => $tokenid], 'id, timecreated');
        if ($token && (time() - (int)$token->timecreated) <= $window) {
            $DB->delete_records('external_tokens', ['id' => $tokenid]);
            self::log('revoketoken', $ip, $userid, '', 'recenttoken', null);
        }
    }

    /** Write an audit log entry. */
    protected static function log(string $eventtype, string $ip, ?int $userid = null,
            string $username = '', string $reason = '', ?int $duration = null): void {
        global $DB;
        $record = (object) [
            'ip' => $ip,
            'userid' => $userid,
            'username' => $username ? core_text::strtolower($username) : null,
            'eventtype' => $eventtype,
            'reason' => $reason,
            'duration' => $duration,
            'timecreated' => time(),
        ];
        $DB->insert_record('tool_bruteforce_audit', $record);
    }

    /** Internal helper to update attempt counters. */
    protected static function record_attempt(?int $userid, ?string $username, ?string $ip, int $now, int $window): void {
        global $DB;
        $conditions = ['userid' => $userid, 'username' => $username, 'ip' => $ip];
        $record = $DB->get_record('tool_bruteforce_attempts', $conditions);
        if ($record) {
            if ($window > 0 && ($now - (int)$record->firstfail) > $window) {
                $record->count = 1;
                $record->firstfail = $now;
            } else {
                $record->count++;
            }
            $record->lastfail = $now;
            $DB->update_record('tool_bruteforce_attempts', $record);
        } else {
            $record = (object) $conditions;
            $record->count = 1;
            $record->firstfail = $now;
            $record->lastfail = $now;
            $DB->insert_record('tool_bruteforce_attempts', $record);
        }
    }

    /** Internal helper to fetch attempts. */
    protected static function get_attempt(?int $userid, ?string $username, ?string $ip) {
        global $DB;
        $record = $DB->get_record('tool_bruteforce_attempts', ['userid' => $userid, 'username' => $username, 'ip' => $ip]);
        if (!$record) {
            $record = (object) ['count' => 0];
        }
        return $record;
    }

    /** Resolve username using userid if needed. */
    protected static function resolve_username(?int $userid, ?string $username): ?string {
        global $DB;
        if ($userid) {
            $u = $DB->get_record('user', ['id' => $userid], 'username', IGNORE_MISSING);
            if ($u) {
                return core_text::strtolower($u->username);
            }
        }
        if ($username !== null) {
            return core_text::strtolower($username);
        }
        return null;
    }

    /** Apply thresholds. */
    protected static function enforce_threshold(string $axis, int $count, ?int $userid, ?string $username, ?string $ip): void {
        $soft = (int) get_config('tool_bruteforce', $axis . '_thresholdsoft');
        $hard = (int) get_config('tool_bruteforce', $axis . '_thresholdhard');
        $softduration = (int) get_config('tool_bruteforce', $axis . '_durationsoft');
        $hardduration = (int) get_config('tool_bruteforce', $axis . '_durationhard');

        if ($hard > 0 && $count >= $hard) {
            self::block($userid, $username, $ip, $hardduration, 'hard');
        } else if ($soft > 0 && $count >= $soft) {
            self::block($userid, $username, $ip, $softduration, 'soft');
        }
    }
}
