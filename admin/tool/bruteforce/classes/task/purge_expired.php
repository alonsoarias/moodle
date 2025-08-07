<?php
namespace tool_bruteforce\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task to purge expired blocks and old attempts.
 */
class purge_expired extends \core\scheduled_task {
    public function get_name() {
        return get_string('pluginname', 'tool_bruteforce');
    }

    public function execute() {
        global $DB;
        $now = time();
        $DB->delete_records_select('tool_bruteforce_blocks', 'unblocktime <= :now', ['now' => $now]);

        // Remove attempts outside of window.
        $window = (int) get_config('tool_bruteforce', 'window');
        if ($window > 0) {
            $expire = $now - $window;
            $DB->delete_records_select('tool_bruteforce_attempts', 'lastfail < :expire', ['expire' => $expire]);
        }
    }
}
