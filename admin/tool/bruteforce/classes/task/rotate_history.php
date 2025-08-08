<?php
namespace tool_bruteforce\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task to purge old history records.
 */
class rotate_history extends \core\scheduled_task {
    public function get_name() {
        return get_string('pluginname', 'tool_bruteforce');
    }

    public function execute() {
        global $DB;
        $days = (int) get_config('tool_bruteforce', 'retention');
        if ($days <= 0) {
            return;
        }
        $cutoff = time() - ($days * DAYSECS);
        $DB->delete_records_select('tool_bruteforce_attempts', 'lastfail < :c', ['c' => $cutoff]);
        $DB->delete_records_select('tool_bruteforce_blocks', 'unblocktime < :c', ['c' => $cutoff]);
        $DB->delete_records_select('tool_bruteforce_oneday', 'unblocktime < :c', ['c' => $cutoff]);
        $DB->delete_records_select('tool_bruteforce_audit', 'timecreated < :c', ['c' => $cutoff]);
    }
}
