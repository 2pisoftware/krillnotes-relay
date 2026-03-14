# swarm-relay

A lightweight store-and-forward sync server for [KrillNotes](https://krillnotes.com). It routes encrypted sync bundles between any combination of peers — devices belonging to the same account, or collaborators sharing a workspace with different accounts. It also simplifies workspace invitations: the inviter uploads an invite package to the relay and shares a single URL; the recipient fetches it without needing a direct connection.

The relay is intentionally dumb: it never sees plaintext data. All bundles are end-to-end encrypted by the client before upload. The relay only routes, stores, and expires them.

## Requirements

- PHP 8.2+
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

## API overview

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
