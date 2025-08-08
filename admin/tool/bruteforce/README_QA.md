# QA checklist for tool_bruteforce

## Checklist
- Single navigation node under *Site administration → Security* pointing to `manage.php`.
- Tabs render Dashboard, Blocks, History, Lists and Settings (linking to `/admin/settings.php?section=tool_bruteforce`).
- Blocks: add, filter, extend and bulk unblock/delete; cache `isblocked` purged after changes.
- History: filters and CSV export respect applied filters.
- Lists: CRUD for whitelist/blacklist with duplicate prevention and cache purge.
- Observers: failed logins accumulate and successful logins reset counters; tokens revoked when blocked.

## Backout plan
- Rename tables back to `tool_bruteforce_userwhitelist` and `tool_bruteforce_userblacklist` if needed.
- Remove test data from user lists and blocks tables.
- Disable rescue mode and purge caches: `php admin/cli/purge_caches.php`.
