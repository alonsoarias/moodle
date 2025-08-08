<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
admin_externalpage_setup('tool_bruteforce_history');

global $DB, $CFG;
require_once($CFG->libdir . '/csvlib.class.php');

$ip = optional_param('ip', '', PARAM_RAW_TRIMMED);
$username = optional_param('username', '', PARAM_RAW_TRIMMED);
$type = optional_param('type', '', PARAM_ALPHA);
$download = optional_param('download', 0, PARAM_BOOL);

$sql = '1=1';
$params = [];
if ($ip !== '') { 
    $sql .= ' AND ip = :ip'; 
    $params['ip'] = $ip; 
}
if ($username !== '') { 
    $sql .= ' AND username = :username'; 
    $params['username'] = core_text::strtolower($username); 
}
if ($type !== '') { 
    $sql .= ' AND eventtype = :type'; 
    $params['type'] = $type; 
}

$records = $DB->get_records_select('tool_bruteforce_audit', $sql, $params, 'timecreated DESC');

if ($download) {
    $exporter = new csv_export_writer();
    $exporter->add_data([
        get_string('time'),
        get_string('ip', 'tool_bruteforce'),
        'userid',
        get_string('username'),
        get_string('event', 'tool_bruteforce'),
        get_string('reason', 'tool_bruteforce'),
        get_string('duration', 'tool_bruteforce')
    ]);
    foreach ($records as $r) {
        $exporter->add_data([
            userdate($r->timecreated), 
            $r->ip, 
            $r->userid, 
            $r->username, 
            $r->eventtype, 
            $r->reason, 
            $r->duration
        ]);
    }
    $exporter->download_file('history');
    exit;
}

echo $OUTPUT->header();

$form = html_writer::start_tag('form', ['method'=>'get']);
$form .= html_writer::empty_tag('input', ['type'=>'text', 'name'=>'ip', 'value'=>$ip, 'placeholder'=>get_string('ip', 'tool_bruteforce')]);
$form .= html_writer::empty_tag('input', ['type'=>'text', 'name'=>'username', 'value'=>$username, 'placeholder'=>get_string('user')]);
$form .= html_writer::empty_tag('input', ['type'=>'text', 'name'=>'type', 'value'=>$type, 'placeholder'=>get_string('type', 'tool_bruteforce')]);
$form .= html_writer::empty_tag('input', ['type'=>'submit', 'value'=>get_string('search')]);
$form .= html_writer::end_tag('form');
echo $form;

$table = new html_table();
$table->head = [
    get_string('time'),
    get_string('ip', 'tool_bruteforce'),
    get_string('user'),
    get_string('event', 'tool_bruteforce'),
    get_string('reason', 'tool_bruteforce'),
    get_string('duration', 'tool_bruteforce')
];

foreach ($records as $r) {
    $userdisp = $r->username;
    if ($r->userid) {
        $user = $DB->get_record('user', ['id'=>$r->userid], '*', IGNORE_MISSING);
        if ($user) { 
            $userdisp = fullname($user).' ('.$user->username.')'; 
        }
    }
    $table->data[] = [
        userdate($r->timecreated), 
        s($r->ip), 
        s($userdisp), 
        s($r->eventtype), 
        s($r->reason), 
        $r->duration
    ];
}

if ($table->data) {
    echo html_writer::table($table);
    $downloadurl = new moodle_url('/admin/tool/bruteforce/history.php', array_merge(['download' => 1], $params));
    echo html_writer::link($downloadurl, get_string('download'));
} else {
    echo $OUTPUT->notification(get_string('none', 'tool_bruteforce'), 'notifymessage');
}

echo $OUTPUT->footer();