# Docker Dev Environment

## Goal

Provide a zero-friction local dev/test environment via `docker compose up`. Developers can hit the relay at `localhost:8080` and run the test suite without installing PHP, SQLite, or ext-sodium locally.

## Scope

Dev and test only. Not intended for production deployment (which uses FTP via GitHub Actions).

## Architecture

Single Docker image based on `php:8.3-cli`. Two Compose services share it:

- **app** — runs `install.php` on startup then starts the PHP built-in dev server on `0.0.0.0:8080`
- **test** — runs `php vendor/bin/phpunit` and exits

### Dockerfile

- Base: `php:8.3-cli`
- Extensions: `pdo_sqlite`, `sodium` (installed via `docker-php-ext-install`)
- Composer: multi-stage copy from `composer:2` image
- Working directory: `/app`
- Deps installed at build time (`composer install`)
- Entrypoint: `docker-entrypoint.sh`

### docker-entrypoint.sh

```bash
#!/bin/sh
set -e
php bin/install.php   # creates DB + runs migrations (idempotent)
exec "$@"             # delegates to CMD (php -S ...)
```

### docker-compose.yml

```yaml
services:
  app:
    build: .
    ports:
      - "8080:8080"
    # No volumes — ephemeral, fresh DB every start

  test:
    build: .
    entrypoint: ["php", "vendor/bin/phpunit"]
    profiles: ["test"]
```

The `test` service uses a Compose profile so it doesn't start with `docker compose up` — you run it explicitly via `docker compose run test`.

### .dockerignore

Exclude `.git`, `vendor/`, `storage/database/`, `storage/bundles/`, `.phpunit.cache/`, `.worktrees/`.

## Files to create

| File | Purpose |
|------|---------|
| `Dockerfile` | Image definition |
| `docker-compose.yml` | Service orchestration |
| `docker-entrypoint.sh` | Auto-init + server start |
| `.dockerignore` | Build context exclusions |

## Files to modify

| File | Change |
|------|--------|
| `README.md` | Add Docker quick start section |

## What this does NOT include

- No Nginx/Apache — uses PHP built-in server
- No process manager (supervisord, etc.)
- No health checks or restart policies
- No persistent volumes — data is ephemeral by design
- No `.env` support — `config/settings.php` defaults are sufficient for local dev
