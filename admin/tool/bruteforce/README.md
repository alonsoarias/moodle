# Bruteforce protection tool for Moodle

This plugin records failed login attempts and blocks users or IP addresses
after a configurable number of failures. It relies on Moodle's core IP
allow and deny lists (`allowedip` and `blockedip`) rather than maintaining
its own copies.

## Disclaimer

This plugin is **incomplete** and missing many features described in the
project specification such as whitelists, notifications, and detailed UI.
Use at your own risk.
