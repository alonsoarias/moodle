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
 * Settings for tool_bruteforce plugin.
 *
 * @package    tool_bruteforce
 * @copyright  2024 The Moodle Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('tool_bruteforce', get_string('pluginname', 'tool_bruteforce'));

    // Soft threshold configuration
    $settings->add(new admin_setting_configtext(
        'tool_bruteforce/thresholdsoft',
        get_string('thresholdsoft', 'tool_bruteforce'),
        get_string('thresholdsoft_desc', 'tool_bruteforce'),
        3,
        PARAM_INT
    ));

    // Soft block duration
    $settings->add(new admin_setting_configduration(
        'tool_bruteforce/durationsoft',
        get_string('durationsoft', 'tool_bruteforce'),
        get_string('durationsoft_desc', 'tool_bruteforce'),
        300
    ));

    // Hard threshold configuration
    $settings->add(new admin_setting_configtext(
        'tool_bruteforce/thresholdhard',
        get_string('thresholdhard', 'tool_bruteforce'),
        get_string('thresholdhard_desc', 'tool_bruteforce'),
        5,
        PARAM_INT
    ));

    // Hard block duration
    $settings->add(new admin_setting_configduration(
        'tool_bruteforce/durationhard',
        get_string('durationhard', 'tool_bruteforce'),
        get_string('durationhard_desc', 'tool_bruteforce'),
        3600
    ));

    // Time window for counting attempts
    $settings->add(new admin_setting_configduration(
        'tool_bruteforce/window',
        get_string('window', 'tool_bruteforce'),
        get_string('window_desc', 'tool_bruteforce'),
        300
    ));

    // One day block threshold
    $settings->add(new admin_setting_configtext(
        'tool_bruteforce/onedaythreshold',
        get_string('onedaythreshold', 'tool_bruteforce'),
        get_string('onedaythreshold_desc', 'tool_bruteforce'),
        50,
        PARAM_INT
    ));

    // Message displayed when access is blocked.
    $settings->add(new admin_setting_configtext(
        'tool_bruteforce/blockedmessage',
        get_string('blockedmessage', 'tool_bruteforce'),
        '',
        get_string('blockedmessage', 'tool_bruteforce')
    ));

    // Time window for revoking freshly created tokens.
    $settings->add(new admin_setting_configduration(
        'tool_bruteforce/tokenrevokewindow',
        get_string('tokenrevokewindow', 'tool_bruteforce'),
        get_string('tokenrevokewindow_desc', 'tool_bruteforce'),
        5
    ));

    // Coalesce window.
    $settings->add(new admin_setting_configduration(
        'tool_bruteforce/coalescewindow',
        get_string('coalescewindow', 'tool_bruteforce'),
        get_string('coalescewindow_desc', 'tool_bruteforce'),
        0
    ));

    $ADMIN->add('security', $settings);
}