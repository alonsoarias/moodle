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
 * Upgrade script for tool_bruteforce.
 *
 * @package    tool_bruteforce
 * @copyright  2024 The Moodle Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute tool_bruteforce upgrade step.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_tool_bruteforce_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2024040200) {
        // Define table tool_bruteforce_whitelist to be created.
        $table = new xmldb_table('tool_bruteforce_whitelist');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('ip', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('comment', XMLDB_TYPE_CHAR, '255', null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table tool_bruteforce_blacklist to be created.
        $table = new xmldb_table('tool_bruteforce_blacklist');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('ip', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('comment', XMLDB_TYPE_CHAR, '255', null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Define table tool_bruteforce_oneday to be created.
        $table = new xmldb_table('tool_bruteforce_oneday');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('ip', XMLDB_TYPE_CHAR, '45', null, XMLDB_NOTNULL);
        $table->add_field('unblocktime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2024040200, 'tool', 'bruteforce');
    }

    if ($oldversion < 2024040300) {
        // Migrate custom whitelist and blacklist tables into core config and drop tables.
        if ($dbman->table_exists('tool_bruteforce_whitelist')) {
            $records = $DB->get_records_menu('tool_bruteforce_whitelist', null, '', 'id, ip');
            if ($records) {
                $current = (string) get_config('allowedip');
                $lines = array_filter(array_map('trim', explode("\n", $current)));
                foreach ($records as $ip) {
                    $lines[] = $ip;
                }
                set_config('allowedip', implode("\n", array_unique($lines)));
            }
            $dbman->drop_table(new xmldb_table('tool_bruteforce_whitelist'));
        }

        if ($dbman->table_exists('tool_bruteforce_blacklist')) {
            $records = $DB->get_records_menu('tool_bruteforce_blacklist', null, '', 'id, ip');
            if ($records) {
                $current = (string) get_config('blockedip');
                $lines = array_filter(array_map('trim', explode("\n", $current)));
                foreach ($records as $ip) {
                    $lines[] = $ip;
                }
                set_config('blockedip', implode("\n", array_unique($lines)));
            }
            $dbman->drop_table(new xmldb_table('tool_bruteforce_blacklist'));
        }

        // Create audit table.
        $table = new xmldb_table('tool_bruteforce_audit');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('ip', XMLDB_TYPE_CHAR, '45', null, XMLDB_NOTNULL);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, null);
        $table->add_field('username', XMLDB_TYPE_CHAR, '100', null, null);
        $table->add_field('eventtype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL);
        $table->add_field('reason', XMLDB_TYPE_CHAR, '255', null, null);
        $table->add_field('duration', XMLDB_TYPE_INTEGER, '10', null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('ip', XMLDB_INDEX_NOTUNIQUE, ['ip']);
        $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2024040300, 'tool', 'bruteforce');
    }

    if ($oldversion < 2024040400) {
        if (!get_config('tool_bruteforce', 'tokenrevokewindow')) {
            set_config('tokenrevokewindow', 5, 'tool_bruteforce');
        }
        upgrade_plugin_savepoint(true, 2024040400, 'tool', 'bruteforce');
    }

    if ($oldversion < 2024040500) {
        if (!get_config('tool_bruteforce', 'coalescewindow')) {
            set_config('coalescewindow', 0, 'tool_bruteforce');
        }
        upgrade_plugin_savepoint(true, 2024040500, 'tool', 'bruteforce');
    }

    if ($oldversion < 2024040600) {
        $table = new xmldb_table('tool_bruteforce_attempts');
        if (!$dbman->field_exists($table, new xmldb_field('username'))) {
            $dbman->add_field($table, new xmldb_field('username', XMLDB_TYPE_CHAR, '100', null, null, null, null));
        }
        if ($dbman->field_exists($table, new xmldb_field('ip'))) {
            $field = new xmldb_field('ip', XMLDB_TYPE_CHAR, '45', null, null, null, null);
            $dbman->change_field_notnull($table, $field);
        }
        $index = new xmldb_index('username', XMLDB_INDEX_NOTUNIQUE, ['username']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $index = new xmldb_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $index = new xmldb_index('ip', XMLDB_INDEX_NOTUNIQUE, ['ip']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $table = new xmldb_table('tool_bruteforce_blocks');
        if (!$dbman->field_exists($table, new xmldb_field('username'))) {
            $dbman->add_field($table, new xmldb_field('username', XMLDB_TYPE_CHAR, '100', null, null, null, null));
        }
        if ($dbman->field_exists($table, new xmldb_field('ip'))) {
            $field = new xmldb_field('ip', XMLDB_TYPE_CHAR, '45', null, null, null, null);
            $dbman->change_field_notnull($table, $field);
        }
        $index = new xmldb_index('username', XMLDB_INDEX_NOTUNIQUE, ['username']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $index = new xmldb_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $index = new xmldb_index('ip', XMLDB_INDEX_NOTUNIQUE, ['ip']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        $index = new xmldb_index('unblocktime', XMLDB_INDEX_NOTUNIQUE, ['unblocktime']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $table = new xmldb_table('tool_bruteforce_audit');
        $index = new xmldb_index('username', XMLDB_INDEX_NOTUNIQUE, ['username']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $table = new xmldb_table('tool_bruteforce_userwhitelist');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('username', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
            $table->add_field('comment', XMLDB_TYPE_CHAR, '255', null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('username_unique', XMLDB_KEY_UNIQUE, ['username']);
            $dbman->create_table($table);
        }

        $table = new xmldb_table('tool_bruteforce_userblacklist');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $table->add_field('username', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL);
            $table->add_field('comment', XMLDB_TYPE_CHAR, '255', null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('username_unique', XMLDB_KEY_UNIQUE, ['username']);
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2024040600, 'tool', 'bruteforce');
    }

    return true;
}
