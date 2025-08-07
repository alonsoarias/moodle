<?php
// Event observers for tool_bruteforce.

$observers = [
    [
        'eventname'   => '\\core\\event\\user_login_failed',
        'callback'    => '\\tool_bruteforce\\observers::user_login_failed',
        'priority'    => 9999,
    ],
    [
        'eventname'   => '\\core\\event\\user_loggedin',
        'callback'    => '\\tool_bruteforce\\observers::user_loggedin',
        'priority'    => 9999,
    ],
];
