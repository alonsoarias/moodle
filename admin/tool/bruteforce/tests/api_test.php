<?php
namespace tool_bruteforce\tests;

defined('MOODLE_INTERNAL') || die();

use advanced_testcase;
use tool_bruteforce\api;
use context_system;

/**
 * Unit tests for bruteforce API.
 */
class api_test extends advanced_testcase {

    public function test_failed_login_creates_block() {
        global $CFG, $DB;
        $this->resetAfterTest();

        // Configure soft threshold settings for IP axis
        set_config('ip_thresholdsoft', 1, 'tool_bruteforce');
        set_config('ip_durationsoft', 60, 'tool_bruteforce');
        set_config('window', 60, 'tool_bruteforce');

        // Test that a single failed login creates a block
        api::failed(null, '127.0.0.1');
        $this->assertTrue(api::is_blocked(null, '127.0.0.1'));
    }

    public function test_successful_login_clears_attempts() {
        global $DB;
        $this->resetAfterTest();

        // Create a user and record failed attempts
        $user = $this->getDataGenerator()->create_user();
        api::failed($user->id, '127.0.0.1');
        
        // Verify attempts are recorded
        $this->assertTrue($DB->record_exists('tool_bruteforce_attempts', ['userid' => $user->id, 'ip' => '127.0.0.1']));
        
        // Record successful login
        api::success($user->id, '127.0.0.1');
        
        // Verify attempts are cleared
        $this->assertFalse($DB->record_exists('tool_bruteforce_attempts', ['userid' => $user->id, 'ip' => '127.0.0.1']));
    }

    public function test_whitelist_prevents_blocking() {
        global $DB;
        $this->resetAfterTest();

        // Add IP to core whitelist
        set_config('allowedip', '127.0.0.1');

        // Configure threshold
        set_config('ip_thresholdsoft', 1, 'tool_bruteforce');
        set_config('ip_durationsoft', 60, 'tool_bruteforce');
        set_config('window', 60, 'tool_bruteforce');

        // Try to trigger a block
        api::failed(null, '127.0.0.1');
        
        // Should not be blocked due to whitelist
        $this->assertFalse(api::is_blocked(null, '127.0.0.1'));
    }

    public function test_blacklist_blocks_immediately() {
        global $DB;
        $this->resetAfterTest();

        // Add IP to core blacklist
        set_config('blockedip', '192.168.1.1');

        // Should be blocked immediately without any failed attempts
        $this->assertTrue(api::is_blocked(null, '192.168.1.1'));
    }

    public function test_oneday_block_threshold() {
        global $DB;
        $this->resetAfterTest();

        // Configure one day threshold
        set_config('onedaythreshold', 2, 'tool_bruteforce');
        set_config('window', 3600, 'tool_bruteforce');

        // Generate multiple failed attempts to trigger one day block
        api::failed(null, '10.0.0.1');
        api::failed(null, '10.0.0.1');

        // Check if one day block was created
        $this->assertTrue($DB->record_exists('tool_bruteforce_oneday', ['ip' => '10.0.0.1']));
        $this->assertTrue(api::is_blocked(null, '10.0.0.1'));
    }

    public function test_token_revoked_when_blocked() {
        global $DB;
        $this->resetAfterTest();

        // Prepare config.
        set_config('tokenrevokewindow', 60, 'tool_bruteforce');

        // Create user and block it.
        $user = $this->getDataGenerator()->create_user();
        $ip = '9.9.9.9';
        api::block($user->id, null, $ip, 120);

        // Simulate token creation from that IP.
        $_SERVER['REMOTE_ADDR'] = $ip;
        $token = (object) [
            'token' => 'abc',
            'userid' => $user->id,
            'tokentype' => 0,
            'timecreated' => time(),
            'contextid' => \context_system::instance()->id,
            'creatorid' => $user->id,
        ];
        $tokenid = $DB->insert_record('external_tokens', $token);

        $event = \core\event\webservice_token_created::create([
            'objectid' => $tokenid,
            'relateduserid' => $user->id,
            'other' => ['auto' => true],
        ]);
        $event->trigger();

        $this->assertFalse($DB->record_exists('external_tokens', ['id' => $tokenid]));
        $this->assertTrue($DB->record_exists('tool_bruteforce_audit', ['eventtype' => 'revoketoken', 'userid' => $user->id]));
    }

    public function test_coalesce_identical_attempts() {
        global $DB;
        $this->resetAfterTest();

        set_config('ip_thresholdsoft', 2, 'tool_bruteforce');
        set_config('ip_durationsoft', 60, 'tool_bruteforce');
        set_config('window', 300, 'tool_bruteforce');
        set_config('coalescewindow', 60, 'tool_bruteforce');

        api::failed(null, '1.2.3.4');
        api::failed(null, '1.2.3.4');

        $record = $DB->get_record('tool_bruteforce_attempts', ['userid' => null, 'ip' => '1.2.3.4']);
        $this->assertEquals(1, $record->count);
        $this->assertFalse(api::is_blocked(null, '1.2.3.4'));
    }

    public function test_user_lists() {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['username' => 'foo']);
        $DB->insert_record('tool_bruteforce_ublacklist', ['username' => 'foo', 'timecreated' => time()]);
        \cache::make('tool_bruteforce', 'userlists')->purge();
        $this->assertTrue(api::is_user_blacklisted($user->id, 'foo'));

        $DB->insert_record('tool_bruteforce_uwhitelist', ['username' => 'bar', 'timecreated' => time()]);
        \cache::make('tool_bruteforce', 'userlists')->purge();
        $this->assertTrue(api::is_user_whitelisted(null, 'bar'));
    }
}
