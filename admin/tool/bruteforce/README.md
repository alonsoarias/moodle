# Bruteforce protection tool for Moodle

This plugin records failed login attempts and blocks users, IP addresses or
the combination according to configurable soft and hard thresholds. It honours
Moodle's core allow/deny IP lists and adds user white/black lists, one-day
blocking, audit history and background tasks. The companion authentication
plugin `auth_bruteforceguard` stops blocked requests on the login page and
revokes freshly created web service tokens.

## Features

* Thresholds per axis (IP, user and user+IP) with independent durations.
* Core `allowedip`/`blockedip` precedence followed by user white/black lists.
* One-day automatic blocks for abusive IPs.
* CSV exportable audit log and management UI under *Site administration → Security*.
* CLI helpers for blocking/unblocking and rescue mode.
* Scheduled tasks for purging expired data and rotating history.

