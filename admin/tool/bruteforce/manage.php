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
 * Unified management page for bruteforce protection.
 *
 * @package    tool_bruteforce
 * @copyright  2024 The Moodle Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$section = optional_param('section', 'dashboard', PARAM_ALPHA);
$context = context_system::instance();

admin_externalpage_setup('tool_bruteforce_manage');

$baseurl = new moodle_url('/admin/tool/bruteforce/manage.php');
$PAGE->set_url($baseurl, ['section' => $section]);

$tabs = [];
$tabs[] = new tabobject('dashboard', new moodle_url($baseurl, ['section' => 'dashboard']),
    get_string('dashboard', 'tool_bruteforce'));
$tabs[] = new tabobject('blocks', new moodle_url($baseurl, ['section' => 'blocks']),
    get_string('blocks', 'tool_bruteforce'));
$tabs[] = new tabobject('history', new moodle_url($baseurl, ['section' => 'history']),
    get_string('history', 'tool_bruteforce'));
$tabs[] = new tabobject('lists', new moodle_url($baseurl, ['section' => 'lists']),
    get_string('lists', 'tool_bruteforce'));
$tabs[] = new tabobject('settings', new moodle_url($CFG->wwwroot . '/admin/settings.php', ['section' => 'tool_bruteforce']),
    get_string('settings'));

echo $OUTPUT->header();

echo $OUTPUT->tabtree($tabs, $section);

switch ($section) {
    case 'blocks':
        require_capability('tool/bruteforce:view', $context);
        require_once($CFG->dirroot . '/admin/tool/bruteforce/classes/form/block_form.php');

        $unblock = optional_param('unblock', 0, PARAM_INT);
        if ($unblock && confirm_sesskey()) {
            require_capability('tool/bruteforce:unblock', $context);
            $DB->delete_records('tool_bruteforce_blocks', ['id' => $unblock]);
            \cache::make('tool_bruteforce', 'isblocked')->purge();
            redirect(new moodle_url($baseurl, ['section' => 'blocks']));
        }

        $mform = new \tool_bruteforce\form\block_form(new moodle_url($baseurl, ['section' => 'blocks']));
        if ($data = $mform->get_data()) {
            require_capability('tool/bruteforce:manage', $context);
            $record = new stdClass();
            $record->ip = trim($data->ip) ?: null;
            $record->username = trim($data->username) ?: null;
            $record->userid = null;
            if ($record->username) {
                $record->userid = $DB->get_field('user', 'id', ['username' => $record->username], IGNORE_MISSING) ?: null;
            }
            $record->reason = trim($data->reason) ?: null;
            $record->timecreated = time();
            $record->unblocktime = $record->timecreated + (int)$data->duration;
            $DB->insert_record('tool_bruteforce_blocks', $record);
            \cache::make('tool_bruteforce', 'isblocked')->purge();
            redirect(new moodle_url($baseurl, ['section' => 'blocks']));
        }

        $mform->display();

        $filterip = optional_param('filterip', '', PARAM_RAW_TRIMMED);
        $filteruser = optional_param('filteruser', '', PARAM_RAW_TRIMMED);

        $params = ['now' => time()];
        $where = 'b.unblocktime > :now';
        if ($filterip !== '') {
            $where .= ' AND b.ip LIKE :ip';
            $params['ip'] = $filterip . '%';
        }
        if ($filteruser !== '') {
            $where .= ' AND (b.username LIKE :uname OR u.username LIKE :uname)';
            $params['uname'] = $filteruser . '%';
        }

        $sql = "SELECT b.*, u.firstname, u.lastname, u.username AS uusername
                  FROM {tool_bruteforce_blocks} b
             LEFT JOIN {user} u ON b.userid = u.id
                 WHERE $where
              ORDER BY b.timecreated DESC";
        $records = $DB->get_records_sql($sql, $params);

        echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mform']);
        echo html_writer::start_div('filters');
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'section', 'value' => 'blocks']);
        echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'filterip',
            'value' => s($filterip), 'placeholder' => get_string('ip', 'tool_bruteforce')]);
        echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'filteruser',
            'value' => s($filteruser), 'placeholder' => get_string('user')]);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('apply', 'tool_bruteforce')]);
        echo html_writer::link(new moodle_url($baseurl, ['section' => 'blocks']), get_string('resetfilters', 'tool_bruteforce'));
        echo html_writer::end_div();
        echo html_writer::end_tag('form');

        $table = new html_table();
        $table->head = [
            get_string('type', 'tool_bruteforce'),
            get_string('ip', 'tool_bruteforce'),
            get_string('user'),
            get_string('reason', 'tool_bruteforce'),
            get_string('created', 'tool_bruteforce'),
            get_string('expiresin', 'tool_bruteforce'),
            get_string('actions')
        ];

        $now = time();
        foreach ($records as $r) {
            $type = 'ip';
            if ($r->ip && ($r->userid || $r->username)) {
                $type = 'user+ip';
            } else if (!$r->ip) {
                $type = 'user';
            }
            $userdisplay = '';
            if ($r->userid && $r->firstname !== null) {
                $userdisplay = fullname((object)['firstname' => $r->firstname, 'lastname' => $r->lastname]) . ' (' . s($r->uusername) . ')';
            } else if ($r->username) {
                $userdisplay = s($r->username);
            }
            $created = userdate($r->timecreated);
            $expires = format_time($r->unblocktime - $now) . ' (' . userdate($r->unblocktime) . ')';
            $action = has_capability('tool/bruteforce:unblock', $context) ?
                html_writer::link(new moodle_url($baseurl, ['section' => 'blocks', 'unblock' => $r->id, 'sesskey' => sesskey()]),
                    get_string('unblock', 'tool_bruteforce')) : '';
            $table->data[] = [$type, s($r->ip), $userdisplay, s($r->reason), $created, $expires, $action];
        }

        if (!empty($table->data)) {
            echo html_writer::table($table);
        } else {
            echo $OUTPUT->notification(get_string('none', 'tool_bruteforce'), 'notifymessage');
        }
        break;

    case 'history':
        require_capability('tool/bruteforce:view', $context);

        $filterip = optional_param('filterip', '', PARAM_RAW_TRIMMED);
        $filteruser = optional_param('filteruser', '', PARAM_RAW_TRIMMED);
        $filterevent = optional_param('filterevent', '', PARAM_ALPHA);
        $download = optional_param('download', '', PARAM_ALPHA);

        $params = [];
        $where = '1=1';
        if ($filterip !== '') {
            $where .= ' AND ip LIKE :ip';
            $params['ip'] = $filterip . '%';
        }
        if ($filteruser !== '') {
            $where .= ' AND username LIKE :uname';
            $params['uname'] = core_text::strtolower($filteruser) . '%';
        }
        if ($filterevent !== '') {
            $where .= ' AND eventtype = :ev';
            $params['ev'] = $filterevent;
        }

        $records = $DB->get_records_sql("SELECT * FROM {tool_bruteforce_audit} WHERE $where ORDER BY timecreated DESC", $params);

        if ($download === 'csv') {
            require_once($CFG->libdir . '/csvlib.class.php');
            $export = new csv_export_writer();
            $export->set_filename('bruteforce_history');
            $export->add_data([get_string('event', 'tool_bruteforce'), get_string('ip', 'tool_bruteforce'),
                get_string('username'), get_string('reason', 'tool_bruteforce'), get_string('duration', 'tool_bruteforce'),
                get_string('created', 'tool_bruteforce')]);
            foreach ($records as $r) {
                $export->add_data([$r->eventtype, $r->ip, $r->username, $r->reason, $r->duration,
                    userdate($r->timecreated)]);
            }
            $export->download_file();
            exit;
        }

        echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mform']);
        echo html_writer::start_div('filters');
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'section', 'value' => 'history']);
        echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'filterip', 'value' => s($filterip),
            'placeholder' => get_string('ip', 'tool_bruteforce')]);
        echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'filteruser', 'value' => s($filteruser),
            'placeholder' => get_string('user')]);
        echo html_writer::select(['' => get_string('event', 'tool_bruteforce'), 'failed' => 'failed',
            'loggedin' => 'loggedin', 'block' => 'block', 'oneday' => 'oneday', 'revoketoken' => 'revoketoken'],
            'filterevent', $filterevent, false);
        echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('apply', 'tool_bruteforce')]);
        echo html_writer::link(new moodle_url($baseurl, ['section' => 'history']),
            get_string('resetfilters', 'tool_bruteforce'));
        echo html_writer::link(new moodle_url($baseurl, ['section' => 'history', 'download' => 'csv',
            'filterip' => $filterip, 'filteruser' => $filteruser, 'filterevent' => $filterevent]),
            get_string('exportcsv', 'tool_bruteforce'), ['class' => 'ml-2']);
        echo html_writer::end_div();
        echo html_writer::end_tag('form');

        $table = new html_table();
        $table->head = [
            get_string('event', 'tool_bruteforce'),
            get_string('ip', 'tool_bruteforce'),
            get_string('username'),
            get_string('reason', 'tool_bruteforce'),
            get_string('duration', 'tool_bruteforce'),
            get_string('created', 'tool_bruteforce'),
        ];

        foreach ($records as $r) {
            $table->data[] = [s($r->eventtype), s($r->ip), s($r->username), s($r->reason),
                $r->duration ? format_time($r->duration) : '', userdate($r->timecreated)];
        }

        if (!empty($table->data)) {
            echo html_writer::table($table);
        } else {
            echo $OUTPUT->notification(get_string('none', 'tool_bruteforce'), 'notifymessage');
        }
        break;

    case 'lists':
        require_capability('tool/bruteforce:manage', $context);
        require_once($CFG->dirroot . '/admin/tool/bruteforce/classes/form/userlist_form.php');

        $delw = optional_param('delw', 0, PARAM_INT);
        $delb = optional_param('delb', 0, PARAM_INT);
        if ($delw && confirm_sesskey()) {
            \tool_bruteforce\api::remove_user_from_list('whitelist', $delw);
            redirect(new moodle_url($baseurl, ['section' => 'lists']));
        }
        if ($delb && confirm_sesskey()) {
            \tool_bruteforce\api::remove_user_from_list('blacklist', $delb);
            redirect(new moodle_url($baseurl, ['section' => 'lists']));
        }

        $whitelistform = new \tool_bruteforce\form\userlist_form(new moodle_url($baseurl,
            ['section' => 'lists']), ['list' => 'whitelist']);
        if ($data = $whitelistform->get_data()) {
            \tool_bruteforce\api::add_user_to_list('whitelist', $data->username, $data->comment);
            redirect(new moodle_url($baseurl, ['section' => 'lists']));
        }
        $blacklistform = new \tool_bruteforce\form\userlist_form(new moodle_url($baseurl,
            ['section' => 'lists']), ['list' => 'blacklist']);
        if ($data = $blacklistform->get_data()) {
            \tool_bruteforce\api::add_user_to_list('blacklist', $data->username, $data->comment);
            redirect(new moodle_url($baseurl, ['section' => 'lists']));
        }

        echo html_writer::tag('h3', get_string('whitelist', 'tool_bruteforce'));
        $whitelistform->display();
        $records = $DB->get_records('tool_bruteforce_uwhitelist', null, 'timecreated DESC');
        $table = new html_table();
        $table->head = [get_string('username'), get_string('comment', 'tool_bruteforce'),
            get_string('created', 'tool_bruteforce'), get_string('actions')];
        foreach ($records as $r) {
            $del = new moodle_url($baseurl, ['section' => 'lists', 'delw' => $r->id, 'sesskey' => sesskey()]);
            $table->data[] = [s($r->username), s($r->comment), userdate($r->timecreated),
                html_writer::link($del, get_string('delete'))];
        }
        echo html_writer::table($table);

        echo html_writer::tag('h3', get_string('blacklist', 'tool_bruteforce'));
        $blacklistform->display();
        $records = $DB->get_records('tool_bruteforce_ublacklist', null, 'timecreated DESC');
        $table = new html_table();
        $table->head = [get_string('username'), get_string('comment', 'tool_bruteforce'),
            get_string('created', 'tool_bruteforce'), get_string('actions')];
        foreach ($records as $r) {
            $del = new moodle_url($baseurl, ['section' => 'lists', 'delb' => $r->id, 'sesskey' => sesskey()]);
            $table->data[] = [s($r->username), s($r->comment), userdate($r->timecreated),
                html_writer::link($del, get_string('delete'))];
        }
        echo html_writer::table($table);
        break;

    case 'dashboard':
    default:
        require_capability('tool/bruteforce:view', $context);
        $now = time();
        $activeip = $DB->count_records_select('tool_bruteforce_blocks',
            'ip IS NOT NULL AND userid IS NULL AND username IS NULL AND unblocktime > :now', ['now' => $now]);
        $activeuser = $DB->count_records_select('tool_bruteforce_blocks',
            'ip IS NULL AND (userid IS NOT NULL OR username IS NOT NULL) AND unblocktime > :now', ['now' => $now]);
        $activepair = $DB->count_records_select('tool_bruteforce_blocks',
            'ip IS NOT NULL AND (userid IS NOT NULL OR username IS NOT NULL) AND unblocktime > :now', ['now' => $now]);
        $oneday = $DB->count_records_select('tool_bruteforce_oneday', 'unblocktime > :now', ['now' => $now]);
        $failed24 = (int)$DB->get_field_sql('SELECT COALESCE(SUM(count),0) FROM {tool_bruteforce_attempts} WHERE lastfail > ?',
            [$now - DAYSECS]);

        $kpi = new html_table();
        $kpi->head = [get_string('ip', 'tool_bruteforce'), get_string('user'), get_string('user') . '+' . get_string('ip', 'tool_bruteforce'),
            'One-day', 'Failures 24h'];
        $kpi->data[] = [$activeip, $activeuser, $activepair, $oneday, $failed24];
        $kpi->attributes['class'] = 'generaltable';
        echo html_writer::tag('h3', get_string('pluginname', 'tool_bruteforce'));
        echo html_writer::table($kpi);
        echo html_writer::div(get_string('allowediplist', 'admin') . ': ' . s($CFG->allowedip));
        echo html_writer::div(get_string('blockediplist', 'admin') . ': ' . s($CFG->blockedip));
        break;
}

echo $OUTPUT->footer();
