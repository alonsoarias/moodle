<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

$now = time();
$activeip = $DB->count_records_select('tool_bruteforce_blocks', 'ip IS NOT NULL AND userid IS NULL AND username IS NULL AND unblocktime > :now', ['now'=>$now]);
$activeuser = $DB->count_records_select('tool_bruteforce_blocks', 'ip IS NULL AND (userid IS NOT NULL OR username IS NOT NULL) AND unblocktime > :now', ['now'=>$now]);
$activepair = $DB->count_records_select('tool_bruteforce_blocks', 'ip IS NOT NULL AND (userid IS NOT NULL OR username IS NOT NULL) AND unblocktime > :now', ['now'=>$now]);
$oneday = $DB->count_records_select('tool_bruteforce_oneday', 'unblocktime > :now', ['now'=>$now]);
$failed24 = (int)$DB->get_field_sql('SELECT COALESCE(SUM(count),0) FROM {tool_bruteforce_attempts} WHERE lastfail > ?', [$now - DAYSECS]);

cli_writeln("Active IP blocks: $activeip");
cli_writeln("Active user blocks: $activeuser");
cli_writeln("Active user+IP blocks: $activepair");
cli_writeln("One-day blocks: $oneday");
cli_writeln("Failed attempts 24h: $failed24");
