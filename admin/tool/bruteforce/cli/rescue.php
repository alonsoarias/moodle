<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir.'/clilib.php');

list($options, $unrecognized) = cli_get_params([
    'enable' => false,
    'disable' => false,
    'help' => false,
], ['h' => 'help']);

if ($options['help'] || (!$options['enable'] && !$options['disable'])) {
    $help = "Toggle rescue mode\n\n--enable   Enable rescue mode\n--disable  Disable rescue mode\n";
    cli_writeln($help);
    exit(0);
}

$state = $options['enable'] ? 1 : 0;
set_config('tool_bruteforce_rescue', $state);
cli_writeln('Rescue mode ' . ($state ? 'enabled' : 'disabled'));
