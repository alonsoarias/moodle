<?php
// Cache definitions for tool_bruteforce.

defined('MOODLE_INTERNAL') || die();

$definitions = [
    // Cached copy of core allowedip/blockedip lists.
    'corelists' => [
        'mode' => cache_store::MODE_REQUEST,
        'ttl' => 300,
    ],
    // Result of is_blocked(userid, ip).
    'isblocked' => [
        'mode' => cache_store::MODE_REQUEST,
        'ttl' => 60,
    ],
    // Markers for coalescing identical attempts.
    'coalesce' => [
        'mode' => cache_store::MODE_REQUEST,
    ],
    // Cached copies of user whitelist/blacklist.
    'userlists' => [
        'mode' => cache_store::MODE_REQUEST,
        'ttl' => 300,
    ],
];
