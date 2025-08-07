<?php
namespace tool_bruteforce\tests;

defined('MOODLE_INTERNAL') || die();

use advanced_testcase;
use tool_bruteforce\api;

/**
 * Unit tests for bruteforce API.
 */
class api_test extends advanced_testcase {
    public function test_failed_login_creates_block() {
        global $CFG, $DB;
        $this->resetAfterTest();
        set_config('thresholdsoft', 1, 'tool_bruteforce');
        set_config('durationsoft', 60, 'tool_bruteforce');
        set_config('window', 60, 'tool_bruteforce');

        api::failed(null, '127.0.0.1');
        $this->assertTrue(api::is_blocked(null, '127.0.0.1'));
    }
}
