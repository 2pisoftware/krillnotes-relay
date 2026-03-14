# Invite Workflow Design

**Date:** 2026-03-14
**Project:** swarm-relay (KrillNotes Relay)
**Status:** Approved

## Overview

Add a public invite-link system to the relay. An authenticated user uploads an encrypted invite blob and receives a shareable URL. Anyone with the URL can download the blob — no account required. The same URL shows a landing page in a browser and returns JSON to the KrillNotes app.

This is a separate concept from the existing sync bundle system. Bundles are routed to specific device keys; invites are publicly accessible by token.

## Data Model

New table `invites` (migration `008_invites.sql`):

```sql
CREATE TABLE invites (
    invite_id      TEXT PRIMARY KEY,
    token          TEXT NOT NULL UNIQUE,
    account_id     TEXT NOT NULL
        REFERENCES accounts(account_id) ON DELETE CASCADE,
    blob_path      TEXT NOT NULL,
    size_bytes     INTEGER NOT NULL,
    expires_at     TEXT NOT NULL,
    download_count INTEGER NOT NULL DEFAULT 0,
    created_at     TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_invites_token ON invites(token);
CREATE INDEX idx_invites_account ON invites(account_id);
```

- `invite_id` — UUID v4 (`Ramsey\Uuid\Uuid::uuid4()->toString()`). Used internally and returned in API responses for reference. All URL routes use `token`, not `invite_id`.
- `token` — `bin2hex(random_bytes(32))` — 64 lowercase hex chars, 256 bits of entropy, URL-safe.
- `expires_at` — set by the client at upload time; relay enforces it on fetch. Maximum allowed: 90 days from creation. (Intentionally longer than `bundle_retention_days = 30`; bundles are short-lived transit objects, invites are user-controlled onboarding links.)
- `download_count` — incremented synchronously on each successful JSON fetch via a single `UPDATE ... SET download_count = download_count + 1` statement (no SELECT-then-UPDATE race).

Blobs stored at `storage/invites/{XX}/{invite_id}.swarm` using the same 2-char subdirectory sharding as bundles. `StorageService::store()` is called with `$invite_id` as the first argument (not `$token`), so the path is derived from the entity ID — consistent with how bundles use `$bundle_id`.

**`ON DELETE CASCADE` note:** if an account is deleted, the DB row is removed automatically but the blob file on disk is not. The cleanup job handles orphaned blobs via `deleteExpiredBefore`, but blobs belonging to cascade-deleted accounts will remain on disk until the next cleanup run. This is an accepted limitation.

**Invite blobs do not count toward `accounts.storage_used`.** They are temporary invite payloads, not sync data. The per-account quota and storage reconciliation in `bin/cleanup.php` remain scoped to the `bundles` table only.

## StorageService

`StorageService` takes a `$basePath` constructor argument and is already wired as a singleton pointing at `storage/bundles/`. For invites, a **second named instance** is registered in `container.php`:

```php
// container.php
'InviteStorageService' => function ($c) {
    return new StorageService($c->get('settings')['storage']['invites_path']);
},
```

Invite handlers receive `InviteStorageService` by name via PHP-DI. No changes to `StorageService` itself.

In `bin/cleanup.php`, which does not use the container, the invite storage service is constructed directly:
```php
$inviteStorage = new StorageService($settings['storage']['invites_path']);
```
This matches the existing pattern for how `StorageService` is used in the cleanup script for bundles.

## API

### POST /invites — Create invite (authenticated)

**Request:**
```json
{
  "payload":    "<base64 encoded encrypted blob>",
  "expires_at": "2026-06-14T00:00:00Z"
}
```

**Validation:**
- `payload` required, must be valid base64, decoded size ≤ `max_bundle_size_bytes` (10 MB)
- `expires_at` required, valid ISO 8601 datetime, must be in the future, must be ≤ 90 days from now

**Response 201:**
```json
{
  "data": {
    "invite_id":  "550e8400-...",
    "token":      "a3f9c2...",
    "url":        "https://swarm.krillnotes.org/invites/a3f9c2...",
    "expires_at": "2026-06-14T00:00:00Z"
  }
}
```

The `url` field is constructed from a `base_url` setting added to `config/settings.php` (e.g. `'base_url' => 'https://swarm.krillnotes.org'`). This avoids deriving the URL from the request host, which is unreliable behind proxies.

---

### GET /invites — List own invites (authenticated)

**Response 200:**
```json
{
  "data": [
    {
      "invite_id":      "...",
      "token":          "a3f9c2...",
      "url":            "https://swarm.krillnotes.org/invites/a3f9c2...",
      "expires_at":     "2026-06-14T00:00:00Z",
      "download_count": 3,
      "created_at":     "2026-03-14T10:00:00Z"
    }
  ]
}
```

---

### DELETE /invites/{token} — Revoke invite (authenticated)

Must be the creator. Deletes the blob file first, then the database record.

**Response 200:**
```json
{ "data": { "ok": true } }
```

Errors: `404 NOT_FOUND` if token unknown, `403 FORBIDDEN` if caller is not the creator.

---

### GET /invites/{token} — Fetch invite (public, no auth)

This route is registered **outside** the authenticated route group in `config/routes.php`, alongside the existing public auth routes.

**Content negotiation** on the `Accept` request header:

- Contains `application/json` → JSON response
- Anything else (browser `text/html,*/*`) → HTML landing page

**JSON response 200:**
```json
{
  "data": {
    "payload":    "<base64>",
    "expires_at": "2026-06-14T00:00:00Z"
  }
}
```

Increments `download_count` synchronously on each successful JSON fetch. HTML fetches do not increment (browser prefetch noise).

**HTML landing page** — dynamically generated, minimal, no JavaScript. Two states:

- **Valid invite:** heading "You've been invited to a KrillNotes workspace", body "To accept this invite, open it in the KrillNotes app. If you don't have KrillNotes yet, download it at krillnotes.com.", and the raw invite URL displayed for copy-paste into the app.
- **Expired / not found:** heading "This invite is no longer valid", body "The invite link has expired or been revoked."

**Error codes:**
- `404 NOT_FOUND` — token does not exist
- `410 GONE` — invite has expired

---

## Components

| Component | Description |
|-----------|-------------|
| `migrations/008_invites.sql` | Schema |
| `src/Repository/InviteRepository.php` | `create()`, `findByToken()`, `listForAccount()`, `delete()`, `deleteExpiredBefore()` |
| `src/Handler/Invite/CreateInviteHandler.php` | POST /invites |
| `src/Handler/Invite/ListInvitesHandler.php` | GET /invites |
| `src/Handler/Invite/RevokeInviteHandler.php` | DELETE /invites/{token} |
| `src/Handler/Invite/FetchInviteHandler.php` | GET /invites/{token} — content negotiation |
| `config/routes.php` | Wire new routes (public fetch outside auth group) |
| `config/container.php` | Wire InviteRepository, InviteStorageService, and handlers |
| `config/settings.php` | Add `storage.invites_path` and `base_url` |
| `bin/cleanup.php` | Delete expired invites + blobs |
| `bin/install.php` | Create `storage/invites/` directory |

## Storage

`config/settings.php` gains:
```php
'base_url' => 'https://swarm.krillnotes.org',
'storage' => [
    'bundles_path' => ...,
    'invites_path' => dirname(__DIR__) . '/storage/invites',
],
```

`bin/install.php` creates `storage/invites/` alongside existing storage dirs.

## Cleanup

`bin/cleanup.php` gets a new clause for expired invites. Order of operations:

1. Fetch all expired rows: `InviteRepository::deleteExpiredBefore(datetime('now'))` returns full rows (including `blob_path`) for all records where `expires_at < $now`
2. For each row, delete the blob: `$inviteStorage->delete($row['blob_path'])`
3. Delete the DB record: `DELETE FROM invites WHERE invite_id = ?`

This fetch-then-delete ordering (blob first, then DB row) ensures no orphaned blobs if the process is interrupted mid-run.

## Out of Scope

- Universal links / deep linking (deferred to app implementation)
- Per-account invite quota
- Rate limiting on the public fetch endpoint (token entropy makes enumeration impractical; acknowledged as accepted risk)
- Password-protected invites
