<?php
require_once(__DIR__ . '/../../../config.php');

require_login();
require_capability('tool/bruteforce:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/admin/tool/bruteforce/index.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('pluginname', 'tool_bruteforce'));
$PAGE->set_heading(get_string('pluginname', 'tool_bruteforce'));

echo $OUTPUT->header();

global $DB;
$blocks = $DB->get_records('tool_bruteforce_blocks');

if ($blocks) {
    echo html_writer::start_tag('ul');
    foreach ($blocks as $block) {
        $user = $block->userid ? $DB->get_record('user', ['id' => $block->userid]) : null;
        $info = $block->ip;
        if ($user) {
            $info .= ' - ' . fullname($user);
        }
        $info .= ' (' . userdate($block->unblocktime) . ')';
        echo html_writer::tag('li', s($info));
    }
    echo html_writer::end_tag('ul');
} else {
    echo $OUTPUT->notification(get_string('none'), 'notifymessage');
}

echo $OUTPUT->footer();
