<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir.'/formslib.php');
require_once($CFG->libdir . '/adminlib.php');
admin_externalpage_setup('tool_bruteforce_userslists');
$context = context_system::instance();

class userlist_form extends moodleform {
    protected function definition() {
        $mform = $this->_form;
        $list = $this->_customdata['list'];
        $mform->addElement('text', 'username', get_string('user')); $mform->setType('username', PARAM_USERNAME);
        $mform->addElement('text', 'comment', get_string('comment', 'tool_bruteforce')); $mform->setType('comment', PARAM_RAW_TRIMMED);
        $mform->addElement('hidden', 'list', $list); $mform->setType('list', PARAM_ALPHA);
        $this->add_action_buttons(true, get_string('add', 'tool_bruteforce'));
    }
    public function validation($data, $files) { $errors = []; if ($data['username']==='') { $errors['username']=get_string('required'); } return $errors; }
}

$whitelistform = new userlist_form(null, ['list'=>'whitelist']);
$blacklistform = new userlist_form(null, ['list'=>'blacklist']);

if ($data = $whitelistform->get_data()) {
    $record = (object)[ 'username'=>core_text::strtolower($data->username), 'comment'=>$data->comment, 'timecreated'=>time() ];
    $DB->insert_record('tool_bruteforce_uwhitelist',$record);
    \cache::make('tool_bruteforce','userlists')->purge();
    redirect(new moodle_url('/admin/tool/bruteforce/userslists.php'));
}
if ($data = $blacklistform->get_data()) {
    $record = (object)[ 'username'=>core_text::strtolower($data->username), 'comment'=>$data->comment, 'timecreated'=>time() ];
    $DB->insert_record('tool_bruteforce_ublacklist',$record);
    \cache::make('tool_bruteforce','userlists')->purge();
    redirect(new moodle_url('/admin/tool/bruteforce/userslists.php'));
}

$delw = optional_param('delw', 0, PARAM_INT);
$delb = optional_param('delb', 0, PARAM_INT);
if ($delw && confirm_sesskey()) {
    $DB->delete_records('tool_bruteforce_uwhitelist', ['id' => $delw]);
    \cache::make('tool_bruteforce', 'userlists')->purge();
    redirect(new moodle_url('/admin/tool/bruteforce/userslists.php'));
}
if ($delb && confirm_sesskey()) {
    $DB->delete_records('tool_bruteforce_ublacklist', ['id' => $delb]);
    \cache::make('tool_bruteforce', 'userlists')->purge();
    redirect(new moodle_url('/admin/tool/bruteforce/userslists.php'));
}

echo $OUTPUT->header();

echo html_writer::tag('h3', get_string('whitelist', 'tool_bruteforce'));
$whitelistform->display();
$whitelist = $DB->get_records('tool_bruteforce_uwhitelist');
if ($whitelist) {
    $table = new html_table();
    $table->head = [get_string('user'), get_string('comment', 'tool_bruteforce'), ''];
    foreach ($whitelist as $w) {
        $del = html_writer::link(new moodle_url('/admin/tool/bruteforce/userslists.php', ['delw'=>$w->id, 'sesskey'=>sesskey()]), get_string('delete'));
        $table->data[] = [s($w->username), s($w->comment), $del];
    }
    echo html_writer::table($table);
}

echo html_writer::tag('h3', get_string('blacklist', 'tool_bruteforce'));
$blacklistform->display();
$blacklist = $DB->get_records('tool_bruteforce_ublacklist');
if ($blacklist) {
    $table = new html_table();
    $table->head = [get_string('user'), get_string('comment', 'tool_bruteforce'), ''];
    foreach ($blacklist as $b) {
        $del = html_writer::link(new moodle_url('/admin/tool/bruteforce/userslists.php', ['delb'=>$b->id, 'sesskey'=>sesskey()]), get_string('delete'));
        $table->data[] = [s($b->username), s($b->comment), $del];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();

