<?php
namespace tool_bruteforce\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\writer;
use context_system;

class provider implements \core_privacy\local\metadata\provider, \core_privacy\local\request\plugin\provider {
    public static function get_metadata(collection $items): collection {
        $items->add_database_table('tool_bruteforce_attempts', ['userid' => 'privacy:metadata:userid', 'username' => 'privacy:metadata:username', 'ip' => 'privacy:metadata:ip'], 'privacy:metadata:attempts');
        $items->add_database_table('tool_bruteforce_blocks', ['userid' => 'privacy:metadata:userid', 'username' => 'privacy:metadata:username', 'ip' => 'privacy:metadata:ip'], 'privacy:metadata:blocks');
        $items->add_database_table('tool_bruteforce_oneday', ['ip' => 'privacy:metadata:ip'], 'privacy:metadata:oneday');
        $items->add_database_table('tool_bruteforce_audit', ['userid' => 'privacy:metadata:userid', 'username' => 'privacy:metadata:username', 'ip' => 'privacy:metadata:ip'], 'privacy:metadata:audit');
        $items->add_database_table('tool_bruteforce_uwhitelist', ['username' => 'privacy:metadata:username', 'comment' => 'privacy:metadata:comment'], 'privacy:metadata:userlist');
        $items->add_database_table('tool_bruteforce_ublacklist', ['username' => 'privacy:metadata:username', 'comment' => 'privacy:metadata:comment'], 'privacy:metadata:userlist');
        return $items;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if (\core\ip_utils::get_ip_address()) { // Dummy to avoid unused var.
        }
        if (self::record_exists($userid)) {
            $contextlist->add_context(context_system::instance());
        }
        return $contextlist;
    }

    protected static function record_exists(int $userid): bool {
        global $DB;
        $exists = $DB->record_exists('tool_bruteforce_attempts', ['userid' => $userid]) ||
                  $DB->record_exists('tool_bruteforce_blocks', ['userid' => $userid]) ||
                  $DB->record_exists('tool_bruteforce_audit', ['userid' => $userid]);
        if (!$exists) {
            $username = $DB->get_field('user', 'username', ['id' => $userid], IGNORE_MISSING);
            if ($username) {
                $username = \core_text::strtolower($username);
                $exists = $DB->record_exists('tool_bruteforce_uwhitelist', ['username' => $username]) ||
                          $DB->record_exists('tool_bruteforce_ublacklist', ['username' => $username]);
            }
        }
        return $exists;
    }

    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        if (!$contextlist->count()) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        $context = context_system::instance();
        $data = new \stdClass();
        $data->attempts = $DB->get_records('tool_bruteforce_attempts', ['userid' => $userid]);
        $data->blocks = $DB->get_records('tool_bruteforce_blocks', ['userid' => $userid]);
        $data->audit = $DB->get_records('tool_bruteforce_audit', ['userid' => $userid]);
        $username = $DB->get_field('user', 'username', ['id' => $userid], IGNORE_MISSING);
        if ($username) {
            $username = \core_text::strtolower($username);
            $data->userwhitelist = $DB->get_records('tool_bruteforce_uwhitelist', ['username' => $username]);
            $data->userblacklist = $DB->get_records('tool_bruteforce_ublacklist', ['username' => $username]);
        }
        writer::with_context($context)->export_data([], $data);
    }

    public static function delete_data_for_all_users_in_context(context_system $context) {
        global $DB;
        $DB->delete_records('tool_bruteforce_attempts');
        $DB->delete_records('tool_bruteforce_blocks');
        $DB->delete_records('tool_bruteforce_oneday');
        $DB->delete_records('tool_bruteforce_audit');
        $DB->delete_records('tool_bruteforce_uwhitelist');
        $DB->delete_records('tool_bruteforce_ublacklist');
    }

    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        if (!$contextlist->count()) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        $DB->delete_records('tool_bruteforce_attempts', ['userid' => $userid]);
        $DB->delete_records('tool_bruteforce_blocks', ['userid' => $userid]);
        $DB->delete_records('tool_bruteforce_audit', ['userid' => $userid]);
        $username = $DB->get_field('user', 'username', ['id' => $userid], IGNORE_MISSING);
        if ($username) {
            $username = \core_text::strtolower($username);
            $DB->delete_records('tool_bruteforce_uwhitelist', ['username' => $username]);
            $DB->delete_records('tool_bruteforce_ublacklist', ['username' => $username]);
        }
    }
}
