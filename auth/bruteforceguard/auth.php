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
 * Authentication guard for tool_bruteforce.
 *
 * This plugin does not authenticate users. It only executes on the login page
 * and blocks access when the tool_bruteforce API reports that the requesting
 * IP or user is blocked.
 *
 * @package    auth_bruteforceguard
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/authlib.php');

/**
 * Guard authentication plugin.
 */
class auth_plugin_bruteforceguard extends auth_plugin_base {
    public function __construct() {
        $this->authtype = 'bruteforceguard';
        $this->config = get_config('auth_bruteforceguard');
    }

    /**
     * Always fail so other plugins can authenticate.
     *
     * @param string $username
     * @param string $password
     * @return bool false to allow other plugins to handle login.
     */
    public function user_login($username, $password) {
        return false;
    }

    /**
     * Hook executed on the login page.
     *
     * Blocks the request early if the IP or user is blocked by tool_bruteforce.
     */
    public function loginpage_hook() {
        global $DB;

        // Resolve requesting IP and (if supplied) the user id of the username.
        $ip = getremoteaddr(null);
        $userid = null;
        $username = optional_param('username', '', PARAM_RAW_TRIMMED);
        if ($username !== '') {
            $record = $DB->get_record('user', ['username' => $username, 'deleted' => 0], 'id', IGNORE_MISSING);
            if ($record) {
                $userid = (int)$record->id;
            }
        }

        if (\tool_bruteforce\api::is_blocked($userid, $ip, $username ?: null)) {
            $msg = (string) get_config('tool_bruteforce', 'blockedmessage');
            if ($msg === '') {
                $msg = get_string('blockedmessage', 'tool_bruteforce');
            }
            throw new \moodle_exception('blocked', 'auth_bruteforceguard', '', $msg);
        }
    }
}
