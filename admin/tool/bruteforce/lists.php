<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/formslib.php');

require_login();
require_capability('tool/bruteforce:manage', context_system::instance());

class bruteforce_list_form extends moodleform {
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('text', 'ip', get_string('ip', 'tool_bruteforce'));
        $mform->setType('ip', PARAM_RAW_TRIMMED);
        $mform->addElement('text', 'comment', get_string('comment', 'tool_bruteforce'));
        $mform->setType('comment', PARAM_TEXT);
        $mform->addElement('hidden', 'list');
        $mform->setType('list', PARAM_ALPHA);
        $this->add_action_buttons(true, get_string('add', 'tool_bruteforce'));
    }
}

$list = required_param('list', PARAM_ALPHA);
$PAGE->set_url(new moodle_url('/admin/tool/bruteforce/lists.php', ['list' => $list]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string($list, 'tool_bruteforce'));
$PAGE->set_heading(get_string('lists', 'tool_bruteforce'));

$form = new bruteforce_list_form(null, ['list' => $list]);
if ($form->is_cancelled()) {
    redirect(new moodle_url('/admin/tool/bruteforce/index.php'));
} else if ($data = $form->get_data()) {
    require_sesskey();
    $record = (object) [
        'ip' => $data->ip,
        'comment' => $data->comment,
        'timecreated' => time(),
    ];
    $table = $list === 'whitelist' ? 'tool_bruteforce_whitelist' : 'tool_bruteforce_blacklist';
    $DB->insert_record($table, $record);
    redirect($PAGE->url);
}

if ($delete = optional_param('delete', 0, PARAM_INT)) {
    require_sesskey();
    $table = $list === 'whitelist' ? 'tool_bruteforce_whitelist' : 'tool_bruteforce_blacklist';
    $DB->delete_records($table, ['id' => $delete]);
    redirect($PAGE->url);
}

$records = $DB->get_records($list === 'whitelist' ? 'tool_bruteforce_whitelist' : 'tool_bruteforce_blacklist');

echo $OUTPUT->header();
$form->display();

if ($records) {
    $table = new html_table();
    $table->head = [get_string('ip', 'tool_bruteforce'), get_string('comment', 'tool_bruteforce'), ''];
    foreach ($records as $r) {
        $deleteurl = new moodle_url($PAGE->url, ['delete' => $r->id, 'sesskey' => sesskey()]);
        $deletebtn = html_writer::link($deleteurl, get_string('delete', 'tool_bruteforce'));
        $table->data[] = [s($r->ip), s($r->comment), $deletebtn];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('none')); // Reuse core string.
}

echo $OUTPUT->footer();
