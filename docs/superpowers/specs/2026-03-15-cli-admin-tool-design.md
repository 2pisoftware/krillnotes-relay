# CLI Admin Tool — Design Spec

## Overview

A read-only CLI tool (`bin/admin.php`) for inspecting the relay database and
storage. Provides a full dashboard view and per-section drill-downs. Designed so
write/action subcommands can be added later.

## Approach

Single file, same bootstrap pattern as `install.php` and `cleanup.php` — direct
repo instantiation, no DI container, no new dependencies.

## Commands

| Command                      | Behaviour                                      |
|------------------------------|-------------------------------------------------|
| `php bin/admin.php`          | Full dashboard (compact summary of all sections) |
| `php bin/admin.php accounts` | Account detail table                            |
| `php bin/admin.php bundles`  | Bundle/workspace detail table                   |
| `php bin/admin.php sessions` | Session, challenge, and reset detail             |
| `php bin/admin.php invites`  | Invite detail table                             |
| `php bin/admin.php health`   | System health — DB, storage, migrations, pragmas |
| `php bin/admin.php help`     | Usage text                                      |

Unknown commands print an error plus the help text.

## Dispatch

```php
$command = $argv[1] ?? 'dashboard';
```

Each section is a standalone function: `showAccounts($pdo, $settings)`, etc.
The `dashboard` command calls all section functions, which detect dashboard vs
detail mode via a parameter (or by checking whether they were called directly).

## Output Format Conventions

- **Sizes**: human-readable with limit context — `54.2 MB / 100 MB (54%)` when
  a setting limit applies, plain `54.2 MB` otherwise.
- **Times**: relative — `3h ago`, `12d ago`, `never` — with absolute date in
  parentheses where useful (e.g. `12d ago (2026-03-03)`).
- **Counts**: plain numbers, with limit context where applicable.
- **Section headers**: `=== ACCOUNTS ===` style separators.
- **Dashboard mode**: compact summary line per section.
- **Detail mode**: summary line plus a formatted table.

### Helper functions (defined once in the file)

- `humanBytes($bytes)` — e.g. `4.2 MB`, `256 KB`
- `relativeTime($timestamp)` — e.g. `3h ago`, `12d ago`, `never`
- `progressBar($current, $max)` — e.g. `54.2 MB / 100 MB (54%)`

## Section Details

### Accounts

**Dashboard line:**
```
Accounts: 42 total, 3 flagged for deletion, 1.2 GB total storage used
```

**Detail table:**

| Email | Devices | Storage | Flagged | Created | Last Poll |
|---|---|---|---|---|---|
| alice@example.com | 2 (2 verified) | 54.2 MB / 100 MB (54%) | — | 30d ago | 2h ago |
| bob@example.com | 1 (0 verified) | 0 B / 100 MB (0%) | yes (12d ago) | 45d ago | never |

- Storage compared against `max_storage_per_account_bytes`.
- Device counts from JOIN on `device_keys` (total + verified).
- Flagged shows time since flagging (relevant to `account_deletion_grace_days`).
- Last Poll from `last_poll_at`; `never` if null.
- Sorted by `created_at` descending.

### Bundles

**Dashboard line:**
```
Bundles: 1,847 pending, 892.4 MB on disk, oldest: 28d ago (expires in 2d)
```

**Detail table (per-workspace):**

| Workspace | Created By | Bundles | Size | Oldest | Newest | Modes |
|---|---|---|---|---|---|---|
| a3f8c2... | alice@example.com | 312 | 148.6 MB | 28d ago | 1h ago | 4 delta, 2 snapshot |
| e91b07... | bob@example.com | 89 | 42.1 MB | 14d ago | 3d ago | 12 delta |

- Created By: account with earliest `MIN(registered_at)` in `mailboxes` for
  that workspace; `(unknown)` if mailbox was deleted.
- Oldest bundle age flagged when within 3 days of `bundle_retention_days`.
- Modes: count breakdown by `mode` column (invite/accept/snapshot/delta).
- Workspace IDs truncated to 8 chars.
- Sorted by bundle count descending.

### Sessions

**Dashboard line:**
```
Sessions: 18 active, oldest: 28d ago (expires in 2d), 3 accounts with active sessions
```

**Detail table (per-account):**

| Account | Sessions | Newest | Oldest | Expires |
|---|---|---|---|---|
| alice@example.com | 3 | 1h ago | 14d ago | in 16d |
| bob@example.com | 1 | 5d ago | 5d ago | in 25d |

- Expires compared against `session_lifetime_seconds`; flags sessions expiring
  within 24h.
- Followed by challenge and password-reset summaries:

```
Challenges: 2 pending (1 registration, 1 device_add), oldest: 3m ago
Password resets: 1 unused, expires in 48m
```

### Invites

**Dashboard line:**
```
Invites: 12 active, 3.4 MB on disk, 47 total downloads
```

**Detail table:**

| Token | Account | Size | Downloads | Created | Expires |
|---|---|---|---|---|---|
| f8a2c1... | alice@example.com | 1.2 MB | 14 | 3d ago | in 4d |
| b91e07... | bob@example.com | 256 KB | 0 | 1h ago | in 7d |

- Token truncated to 8 chars.
- Flags invites expiring within 24h.
- Sorted by `created_at` descending.

### Health

**Dashboard line:**
```
Health: DB 4.2 MB, blobs 892.4 MB, invites 3.4 MB, 8/8 migrations applied, WAL ok
```

**Detail view:**

```
=== DATABASE ===
File:         storage/database/relay.sqlite (4.2 MB)
Journal:      WAL ✓
Foreign keys: ON ✓
Migrations:   8/8 applied

=== STORAGE ===
Bundles dir:  892.4 MB (1,847 files)
Invites dir:  3.4 MB (12 files)
Total disk:   899.8 MB

=== SETTINGS ===
Bundle retention:     30 days
Session lifetime:     30 days
Challenge lifetime:   5 minutes
Reset token lifetime: 1 hour
Max bundle size:      10 MB
Max storage/account:  100 MB
Deletion grace:       90 days
Min poll interval:    60 seconds
```

- Pragma values read via `PRAGMA journal_mode` and `PRAGMA foreign_keys`.
- Migration count: compare files in `migrations/` against `_migrations` rows.
- Blob file counts via `glob()` on storage directories.
- Settings printed for quick reference.

## Future Extensibility

The subcommand structure allows adding write actions later, e.g.:

```
php bin/admin.php accounts delete <account_id>
php bin/admin.php sessions revoke <token>
php bin/admin.php bundles purge <workspace_id>
```

These would be additional branches in the dispatch logic — no structural changes
needed.

## Files Changed

- **New**: `bin/admin.php` — the CLI tool (single file, no new classes)
- **No changes** to existing code, dependencies, or schema.
