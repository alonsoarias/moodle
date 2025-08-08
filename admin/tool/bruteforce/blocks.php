<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
admin_externalpage_setup('tool_bruteforce_blocks');
global $DB;
$context = context_system::instance();

$unblock = optional_param('unblock', 0, PARAM_INT);
if ($unblock && confirm_sesskey()) {
    require_capability('tool/bruteforce:unblock', $context);
    $DB->delete_records('tool_bruteforce_blocks', ['id' => $unblock]);
    \cache::make('tool_bruteforce', 'isblocked')->purge();
    redirect(new moodle_url('/admin/tool/bruteforce/blocks.php'));
}

echo $OUTPUT->header();

$now = time();
$records = $DB->get_records_select('tool_bruteforce_blocks', 'unblocktime > :now', ['now'=>$now], 'timecreated DESC');
$table = new html_table();
$table->head = [get_string('type', 'tool_bruteforce'), get_string('ip', 'tool_bruteforce'), get_string('user'),
    get_string('time'), ''];
foreach ($records as $r) {
    $type = 'ip';
    $userdisplay = '';
    if ($r->ip && ($r->userid || $r->username)) {
        $type = 'user+ip';
    } else if (!$r->ip) {
        $type = 'user';
    }
    if ($r->userid) {
        $user = $DB->get_record('user', ['id'=>$r->userid], '*', IGNORE_MISSING);
        if ($user) {
            $userdisplay = fullname($user) . ' (' . s($user->username) . ')';
        }
    } else if ($r->username) {
        $userdisplay = s($r->username);
    }
    $expires = userdate($r->unblocktime);
    $action = has_capability('tool/bruteforce:unblock', $context) ?
        html_writer::link(new moodle_url('/admin/tool/bruteforce/blocks.php', ['unblock'=>$r->id, 'sesskey'=>sesskey()]), get_string('delete')) : '';
    $table->data[] = [$type, s($r->ip), $userdisplay, $expires, $action];
}
if ($table->data) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('none', 'tool_bruteforce'), 'notifymessage');
}

echo $OUTPUT->footer();
