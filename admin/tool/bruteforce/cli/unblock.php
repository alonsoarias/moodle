<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir.'/clilib.php');

list($options, $unrecognized) = cli_get_params([
    'id' => null,
    'ip' => null,
    'user' => null,
    'help' => false,
], ['h' => 'help']);

if ($options['help'] || (!$options['id'] && !$options['ip'] && !$options['user'])) {
    $help = "Unblock entries
Options:
--id=ID
--ip=IP
--user=username
";
    cli_writeln($help);
    exit(0);
}

if ($options['id']) {
    $DB->delete_records('tool_bruteforce_blocks', ['id' => $options['id']]);
} else {
    $conditions = [];
    if ($options['ip']) { $conditions['ip'] = $options['ip']; }
    if ($options['user']) { $conditions['username'] = core_text::strtolower($options['user']); }
    $DB->delete_records('tool_bruteforce_blocks', $conditions);
}
\cache::make('tool_bruteforce', 'isblocked')->purge();
cli_writeln('Unblocked');
