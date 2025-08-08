<?php
// Settings for tool_bruteforce plugin.

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('tool_bruteforce', get_string('pluginname', 'tool_bruteforce'));

    // IP axis.
    $settings->add(new admin_setting_heading('tool_bruteforce_ip', get_string('ipaxis', 'tool_bruteforce'), ''));
    $settings->add(new admin_setting_configtext('tool_bruteforce/ip_thresholdsoft',
        get_string('thresholdsoft', 'tool_bruteforce'), '', 3, PARAM_INT));
    $settings->add(new admin_setting_configduration('tool_bruteforce/ip_durationsoft',
        get_string('durationsoft', 'tool_bruteforce'), '', 300));
    $settings->add(new admin_setting_configtext('tool_bruteforce/ip_thresholdhard',
        get_string('thresholdhard', 'tool_bruteforce'), '', 5, PARAM_INT));
    $settings->add(new admin_setting_configduration('tool_bruteforce/ip_durationhard',
        get_string('durationhard', 'tool_bruteforce'), '', 3600));

    // User axis.
    $settings->add(new admin_setting_heading('tool_bruteforce_user', get_string('useraxis', 'tool_bruteforce'), ''));
    $settings->add(new admin_setting_configtext('tool_bruteforce/user_thresholdsoft',
        get_string('thresholdsoft', 'tool_bruteforce'), '', 3, PARAM_INT));
    $settings->add(new admin_setting_configduration('tool_bruteforce/user_durationsoft',
        get_string('durationsoft', 'tool_bruteforce'), '', 300));
    $settings->add(new admin_setting_configtext('tool_bruteforce/user_thresholdhard',
        get_string('thresholdhard', 'tool_bruteforce'), '', 5, PARAM_INT));
    $settings->add(new admin_setting_configduration('tool_bruteforce/user_durationhard',
        get_string('durationhard', 'tool_bruteforce'), '', 3600));

    // Pair axis.
    $settings->add(new admin_setting_heading('tool_bruteforce_pair', get_string('pairaxis', 'tool_bruteforce'), ''));
    $settings->add(new admin_setting_configtext('tool_bruteforce/pair_thresholdsoft',
        get_string('thresholdsoft', 'tool_bruteforce'), '', 3, PARAM_INT));
    $settings->add(new admin_setting_configduration('tool_bruteforce/pair_durationsoft',
        get_string('durationsoft', 'tool_bruteforce'), '', 300));
    $settings->add(new admin_setting_configtext('tool_bruteforce/pair_thresholdhard',
        get_string('thresholdhard', 'tool_bruteforce'), '', 5, PARAM_INT));
    $settings->add(new admin_setting_configduration('tool_bruteforce/pair_durationhard',
        get_string('durationhard', 'tool_bruteforce'), '', 3600));

    // Misc settings.
    $settings->add(new admin_setting_configtext('tool_bruteforce/onedaythreshold',
        get_string('onedaythreshold', 'tool_bruteforce'), '', 50, PARAM_INT));
    $settings->add(new admin_setting_configduration('tool_bruteforce/onedayduration',
        get_string('onedayduration', 'tool_bruteforce'), '', DAYSECS));
    $settings->add(new admin_setting_configtext('tool_bruteforce/blockedmessage',
        get_string('blockedmessage', 'tool_bruteforce'), '', get_string('blockedmessage', 'tool_bruteforce')));
    $settings->add(new admin_setting_configduration('tool_bruteforce/tokenrevokewindow',
        get_string('tokenrevokewindow', 'tool_bruteforce'), '', 5));
    $settings->add(new admin_setting_configduration('tool_bruteforce/coalescewindow',
        get_string('coalescewindow', 'tool_bruteforce'), '', 0));
    $settings->add(new admin_setting_configtext('tool_bruteforce/window',
        get_string('window', 'tool_bruteforce'), '', 300, PARAM_INT));
    $settings->add(new admin_setting_configtext('tool_bruteforce/retention',
        get_string('retention', 'tool_bruteforce'), '', 30, PARAM_INT));

    $ADMIN->add('security', $settings);
}
