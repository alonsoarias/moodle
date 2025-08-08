<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
admin_externalpage_setup('tool_bruteforce_dashboard');
global $CFG, $DB;

echo $OUTPUT->header();

$now = time();
$activeip = $DB->count_records_select('tool_bruteforce_blocks', 'ip IS NOT NULL AND userid IS NULL AND username IS NULL AND unblocktime > :now', ['now'=>$now]);
$activeuser = $DB->count_records_select('tool_bruteforce_blocks', 'ip IS NULL AND (userid IS NOT NULL OR username IS NOT NULL) AND unblocktime > :now', ['now'=>$now]);
$activepair = $DB->count_records_select('tool_bruteforce_blocks', 'ip IS NOT NULL AND (userid IS NOT NULL OR username IS NOT NULL) AND unblocktime > :now', ['now'=>$now]);
$oneday = $DB->count_records_select('tool_bruteforce_oneday', 'unblocktime > :now', ['now'=>$now]);

$failed24 = (int)$DB->get_field_sql('SELECT COALESCE(SUM(count),0) FROM {tool_bruteforce_attempts} WHERE lastfail > ?', [$now - DAYSECS]);

echo html_writer::tag('h3', get_string('pluginname', 'tool_bruteforce'));
$kpi = new html_table();
$kpi->head = [get_string('ip', 'tool_bruteforce'), get_string('user', 'tool_bruteforce'), get_string('user') . '+' . get_string('ip', 'tool_bruteforce'), 'One-day', 'Failures 24h'];
$kpi->data[] = [$activeip, $activeuser, $activepair, $oneday, $failed24];
$kpi->attributes['class'] = 'generaltable';
echo html_writer::table($kpi);

echo html_writer::div(get_string('allowediplist', 'admin') . ': ' . s($CFG->allowedip));
echo html_writer::div(get_string('blockediplist', 'admin') . ': ' . s($CFG->blockedip));

echo $OUTPUT->footer();

