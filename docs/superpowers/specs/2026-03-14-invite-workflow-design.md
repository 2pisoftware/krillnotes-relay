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

- `invite_id` — UUID v4
- `token` — 32 random bytes as hex (64 chars, 256 bits of entropy)
- `expires_at` — set by the client at upload time; relay enforces it on fetch
- `download_count` — incremented on each successful public fetch

Blobs stored at `storage/invites/{XX}/{invite_id}.swarm` using the same 2-char subdirectory sharding as bundles. The existing `StorageService` is extended with an `invites` storage path from `config/settings.php`.

## API

### POST /invites — Create invite (authenticated)

**Request:**
```json
{
  "payload":    "<base64 encoded encrypted blob>",
  "expires_at": "2026-06-14T00:00:00Z"
}
```

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

Validation: `payload` required, base64-decodable, decoded size ≤ `max_bundle_size_bytes` (10 MB). `expires_at` required, valid ISO 8601, must be in the future.

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

Must be the creator. Deletes the database record and the blob file.

**Response 200:**
```json
{ "data": { "deleted": true } }
```

Errors: `404 NOT_FOUND` if token unknown, `403 FORBIDDEN` if caller is not the creator.

---

### GET /invites/{token} — Fetch invite (public, no auth)

**Content negotiation** on the `Accept` header:

- `Accept: application/json` → JSON response
- Anything else (browser default `text/html`) → HTML landing page

**JSON response 200:**
```json
{
  "data": {
    "payload":    "<base64>",
    "expires_at": "2026-06-14T00:00:00Z"
  }
}
```

Increments `download_count` on each successful fetch.

**HTML response:** Minimal page informing the visitor the invite is valid and directing them to install KrillNotes from krillnotes.com to accept it. If expired or not found, a friendly error page.

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
| `config/routes.php` | Wire new routes |
| `config/container.php` | Wire InviteRepository + handlers |
| `config/settings.php` | Add `storage.invites_path` |
| `bin/cleanup.php` | Delete expired invites + blobs |

## Storage

`config/settings.php` gains:
```php
'storage' => [
    'bundles_path' => ...,
    'invites_path' => dirname(__DIR__) . '/storage/invites',
],
```

`bin/install.php` creates `storage/invites/` alongside existing dirs.

## Cleanup

`bin/cleanup.php` gets a new clause using `InviteRepository::deleteExpiredBefore(now)`, iterating results to delete blobs via `StorageService` before removing DB records — same pattern as bundle cleanup.

## Out of Scope

- Universal links / deep linking (deferred to app implementation)
- Per-account invite quota
- Rate limiting on the public fetch endpoint (token entropy makes enumeration impractical)
- Password-protected invites
