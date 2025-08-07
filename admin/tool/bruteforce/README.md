# Bruteforce protection tool for Moodle

This is an experimental implementation that records failed login attempts
and blocks users or IP addresses after a configurable number of failures.

The implementation is intentionally minimal and serves as a starting point
for further development. It uses Moodle's events API to listen for
`\core\event\user_login_failed` and `\core\event\user_loggedin` events and
stores counters in custom database tables.

## Disclaimer

This plugin is **incomplete** and missing many features described in the
project specification such as whitelists, notifications, and detailed UI.
Use at your own risk.
