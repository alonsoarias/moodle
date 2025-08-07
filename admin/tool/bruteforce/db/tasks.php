<?php
// Scheduled tasks for tool_bruteforce.

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\\tool_bruteforce\\task\\purge_expired',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*'
    ],
];
