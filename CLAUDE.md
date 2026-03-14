# swarm-relay — Claude project instructions

## What this is

A PHP 8.3 store-and-forward sync relay for KrillNotes. Clients upload end-to-end
encrypted bundles; the relay routes them to recipients by device key and holds them
until polled. Also used for workspace invitations (invite-by-URL).

Stack: Slim 4, PHP-DI, SQLite (WAL), libsodium (Ed25519 / X25519 PoP handshake).

## PHP binary

Always use the Homebrew PHP 8.3 binary:

```
/opt/homebrew/opt/php@8.3/bin/php
```

Or just `php` if the PATH is set (it is after sourcing `~/.zshrc`).

## Key commands

```bash
# Install dependencies
php composer.phar install

# Start dev server (document root is public/)
php -S localhost:8080 -t public/ public/index.php

# First-time setup (creates SQLite DB, runs migrations)
php bin/install.php

# Run tests
php vendor/bin/phpunit

# Cleanup cron (expires bundles, sessions, flagged accounts)
php bin/cleanup.php

# Admin dashboard (read-only DB inspection)
php bin/admin.php              # Full dashboard
php bin/admin.php accounts     # Account details
php bin/admin.php bundles      # Bundle/workspace details
php bin/admin.php sessions     # Session/challenge/reset details
php bin/admin.php invites      # Invite details
php bin/admin.php health       # System health + settings
```

## Project layout

```
src/
  Handler/        # Slim request handlers (one class per route)
  Middleware/     # Auth, JSON body parsing, rate limiting
  Repository/     # PDO repository classes (one per table, returns array|null)
  Service/        # CryptoService, AuthService, StorageService, BundleRoutingService
  Database/       # Connection factory + Migrator
config/
  settings.php    # All tunables (limits, lifetimes, paths)
  container.php   # PHP-DI bindings
  routes.php      # All 15 routes
migrations/       # 001–007 SQLite .sql files (run once, tracked in _migrations table)
storage/
  database/       # relay.sqlite lives here
  bundles/        # .swarm blob files, sharded by 2-char prefix (like Git objects)
public/           # Document root — index.php + .htaccess only
bin/              # CLI scripts (install.php, cleanup.php)
tests/            # PHPUnit — Unit/ and Integration/
```

## Architecture rules

- Each Repository owns exactly one table; returns `array|null`, no ORM.
- Handlers are invokable classes wired by PHP-DI; never instantiate them manually.
- All settings come from `config/settings.php` — never hardcode limits.
- Bundle blobs are stored as `storage/bundles/{XX}/{uuid}.swarm` (2-char subdirectory sharding).

## Never touch

- `storage/database/*.sqlite` — the live database; never delete or overwrite it.
- `storage/bundles/` contents — live user data.
- The `_migrations` table — managed solely by `Migrator`.

## Testing

Tests use `:memory:` SQLite where possible; WAL-mode tests use a temp file.
The `phpunit.ini` sets `auto_prepend_file = tests/sodium_memzero_polyfill.php`
to provide a no-op `sodium_memzero()` for environments without `ext-sodium`.
With Homebrew PHP 8.3, `ext-sodium` is available natively so the polyfill is inert.

## Deployment

Push to `main` triggers `.github/workflows/deploy-ftp-dev.yml`, which runs
`composer install --no-dev` in CI (PHP 8.3) then FTP-deploys changed files.
Requires four repo secrets: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_SERVER_DIR`.
