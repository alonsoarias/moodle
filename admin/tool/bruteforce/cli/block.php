<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir.'/clilib.php');

list($options, $unrecognized) = cli_get_params([
    'ip' => null,
    'user' => null,
    'duration' => 3600,
    'reason' => '',
    'help' => false,
], ['h' => 'help']);

if ($options['help'] || (!$options['ip'] && !$options['user'])) {
    $help = "Block an IP, user or combination
Options:
--ip=IP
--user=username
--duration=seconds
--reason=text
";
    cli_writeln($help);
    exit(0);
}

$userid = null;
$username = $options['user'];
if ($username) {
    $rec = $DB->get_record('user', ['username' => $username, 'deleted'=>0], 'id', IGNORE_MISSING);
    if ($rec) { $userid = $rec->id; }
}
\tool_bruteforce\api::block($userid, $username, $options['ip'], (int)$options['duration'], $options['reason']);
cli_writeln('Block created');
