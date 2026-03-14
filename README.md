# swarm-relay

A lightweight store-and-forward sync server for [KrillNotes](https://krillnotes.com). It routes encrypted sync bundles between any combination of peers — devices belonging to the same account, or collaborators sharing a workspace with different accounts. It also simplifies workspace invitations: the inviter uploads an invite package to the relay and shares a single URL; the recipient fetches it without needing a direct connection.

The relay is intentionally dumb: it never sees plaintext data. All bundles are end-to-end encrypted by the client before upload. The relay only routes, stores, and expires them.

## Requirements

- PHP 8.3+
- `ext-sodium` (libsodium)
- `ext-pdo_sqlite`
- Composer
- A web server with URL rewriting (Apache with `mod_rewrite`, or Nginx)

## Installation

### 1. Clone and install dependencies

```bash
git clone https://github.com/your-org/swarm-relay.git
cd swarm-relay
composer install --no-dev --optimize-autoloader
```

### 2. Run the install script

```bash
php bin/install.php
```

This creates `storage/database/relay.sqlite` and `storage/bundles/`, then runs all database migrations.

### 3. Configure your web server

Point the document root to the `public/` directory, not the project root.

**Apache** — `.htaccess` rewriting is already in `public/.htaccess`. Enable `mod_rewrite` and set `AllowOverride All`.

**Nginx** example:

```nginx
server {
    root /path/to/swarm-relay/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 4. Set storage directory permissions

```bash
chmod 750 storage/database storage/bundles
```

The web server process must be able to read and write both directories. The project root and `src/` should **not** be web-accessible.

### 5. Set up the cleanup cron

Expired bundles, sessions, and flagged accounts are not cleaned up automatically. Add a cron entry to run the cleanup script periodically:

```cron
0 * * * * /usr/bin/php /path/to/swarm-relay/bin/cleanup.php >> /var/log/relay-cleanup.log 2>&1
```

## Deployment to shared hosting via FTP

A GitHub Actions workflow is included at `.github/workflows/deploy-ftp-dev.yml`. It runs `composer install` in CI then uploads changed files to your host on every push to `main`.

Add these four secrets to your GitHub repository (Settings → Secrets → Actions):

| Secret | Description |
|--------|-------------|
| `FTP_SERVER` | FTP hostname, e.g. `ftp.yourhost.com` |
| `FTP_USERNAME` | FTP username |
| `FTP_PASSWORD` | FTP password |
| `FTP_SERVER_DIR` | Remote destination path, e.g. `/relay/` |

After the first deploy, SSH into the host (or use the hosting control panel's terminal) and run:

```bash
php bin/install.php
```

The workflow intentionally **never overwrites** `storage/database/*.sqlite` or `storage/bundles/` so your live data is safe on every push.

If your host only supports plain FTP (not FTPS), change `protocol: ftps` to `protocol: ftp` in the workflow file.

## Local development server

```bash
composer install
php bin/install.php
php -S localhost:8080 -t public/ public/index.php
```

## Admin tool

A read-only CLI tool for inspecting the live database and storage:

```bash
php bin/admin.php              # Full dashboard (all sections)
php bin/admin.php accounts     # Account list with device counts, storage, and flagged status
php bin/admin.php bundles      # Pending bundles grouped by workspace
php bin/admin.php sessions     # Active sessions, pending challenges, unused password resets
php bin/admin.php invites      # Active invites with download counts
php bin/admin.php health       # DB pragmas, migration status, storage sizes, and settings
php bin/admin.php help         # Show usage
```

## API overview

> **Full reference:** [`docs/api.md`](docs/api.md) — every endpoint with request/response fields, error codes, and the PoP handshake protocol.

All request and response bodies are JSON. Authenticated endpoints require a `Bearer` token in the `Authorization` header.

### Authentication

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/auth/register` | — | Begin registration; returns a proof-of-possession challenge |
| POST | `/auth/register/verify` | — | Submit decrypted challenge nonce; returns session token |
| POST | `/auth/login` | — | Email + password login; returns session token |
| POST | `/auth/logout` | ✓ | Invalidate current session |
| POST | `/auth/reset-password` | — | Request a password reset token (always returns 200) |
| POST | `/auth/reset-password/confirm` | — | Set a new password using reset token |

### Account & devices

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/account` | ✓ | Return account info, device keys, and storage usage |
| DELETE | `/account` | ✓ | Flag account for deletion (90-day grace period) |
| POST | `/account/devices` | ✓ | Add a second device; returns a PoP challenge |
| POST | `/account/devices/verify` | ✓ | Verify the new device's challenge |
| DELETE | `/account/devices/{device_key}` | ✓ | Remove a device key |

### Mailboxes

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/mailboxes` | ✓ | Create a mailbox for a workspace |
| DELETE | `/mailboxes/{workspace_id}` | ✓ | Delete a mailbox |
| GET | `/mailboxes` | ✓ | List mailboxes with pending bundle counts |

### Bundles

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/bundles` | ✓ | Upload an encrypted bundle for routing to recipients |
| GET | `/bundles` | ✓ | List bundles waiting for this device (min 60s between polls) |
| GET | `/bundles/{bundle_id}` | ✓ | Download a single bundle payload |
| DELETE | `/bundles/{bundle_id}` | ✓ | Delete a bundle after successful sync |

### Invites

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/invites` | ✓ | Upload an encrypted invite blob; returns a shareable URL |
| GET | `/invites` | ✓ | List own invites with download counts |
| DELETE | `/invites/{token}` | ✓ | Revoke an invite (owner only) |
| GET | `/invites/{token}` | — | Fetch invite blob (JSON) or landing page (browser); no auth required |

The fetch endpoint performs content negotiation on the `Accept` header: requests containing `application/json` receive the base64-encoded blob and `expires_at`; all other requests receive a minimal HTML landing page. Only JSON fetches increment the download counter.

**Create request body:**
```json
{ "payload": "<base64 encoded encrypted blob>", "expires_at": "2026-06-14T00:00:00Z" }
```
`expires_at` must be in the future and at most 90 days from now. `payload` decoded size must not exceed the bundle size limit (10 MB).

**Create response (201):**
```json
{ "data": { "invite_id": "…", "token": "…", "url": "https://swarm.krillnotes.org/invites/…", "expires_at": "…" } }
```

**Fetch response (200, JSON):**
```json
{ "data": { "payload": "<base64>", "expires_at": "…" } }
```

Error `410 Gone` is returned when the invite has expired; `404 Not Found` when the token does not exist.

## Registration flow (proof of possession)

Registration is a two-step handshake to verify the client controls its Ed25519 private key:

1. **POST /auth/register** — send email, password, `identity_uuid`, and `device_public_key` (Ed25519 public key, hex). The server returns an `encrypted_nonce` and a `server_public_key`.
2. Client converts its Ed25519 secret key to X25519, builds a `crypto_box` keypair with the server's ephemeral public key, and decrypts the nonce.
3. **POST /auth/register/verify** — send `device_public_key` and the decrypted `nonce`. On success the server returns a `session_token`.

## Default limits

| Setting | Default |
|---------|---------|
| Session lifetime | 30 days |
| Challenge lifetime | 5 minutes |
| Password reset token lifetime | 1 hour |
| Max bundle size | 10 MB |
| Max storage per account | 100 MB |
| Bundle retention | 30 days |
| Account deletion grace period | 90 days |
| Minimum poll interval | 60 seconds |

## License

MPL-2.0
