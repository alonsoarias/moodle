<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

$enable = optional_param('enable', null, PARAM_BOOL);
if ($enable === null) {
    cli_error('Specify --enable=1 or --enable=0');
}
set_config('tool_bruteforce_rescue', $enable);
cli_writeln('Rescue mode ' . ($enable ? 'enabled' : 'disabled'));
