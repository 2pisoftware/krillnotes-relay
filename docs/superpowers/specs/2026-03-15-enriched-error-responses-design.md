# Design: Enriched Error Responses Across All API Endpoints

**Date:** 2026-03-15
**Status:** Approved

---

## Problem

Three bugs share a single root cause: `DeviceKeyRepository::findAccountByKey()` contains `AND dk.verified = 1` — correct for bundle routing, but wrong for ownership checks and duplicate-key detection.

### Observed failures

| Endpoint | Symptom | Root cause |
|----------|---------|------------|
| `POST /bundles` | `routed_to: 0`, HTTP 201, no explanation | `findAccountByKey` skips unverified recipients silently |
| `POST /account/devices` | Unhandled PDO exception (500) on re-submit of an unverified key | `findAccountByKey` misses unverified keys → duplicate INSERT hits SQLite UNIQUE constraint |
| `GET /bundles/{bundle_id}` | 403 FORBIDDEN to the legitimate recipient | `findAccountByKey` returns null for unverified key → ownership check fails |

---

## Design

### 1. New repository method

Add `DeviceKeyRepository::findByKey(string $key): ?array`.

```sql
SELECT device_public_key, account_id, verified, added_at
FROM device_keys
WHERE device_public_key = ?
```

- No `verified` filter, no JOIN to `accounts`.
- Returns the `device_keys` row or `null`.
- This is the general-purpose key lookup; `findAccountByKey` (verified=1 + JOIN) is retained exclusively for bundle routing.

### 2. Call-site fixes

**`AddDeviceHandler`** (`src/Handler/Account/AddDeviceHandler.php`)

Replace `findAccountByKey($devicePublicKey)` with `findByKey($devicePublicKey)`. An unverified key already in the database now correctly returns 409 `KEY_EXISTS` instead of a PDO exception.

**`DownloadBundleHandler`** (`src/Handler/Bundle/DownloadBundleHandler.php`)

Replace `findAccountByKey($bundle['recipient_device_key'])` with `findByKey(...)`. Ownership check (`$row['account_id'] !== $accountId`) is logically identical but no longer fails for unverified recipient keys.

**`BundleRoutingService`** (`src/Service/BundleRoutingService.php`)

Replace `findAccountByKey($recipientKey)` with `findByKey($recipientKey)` and branch explicitly:

```
key absent from DB       → skipped['unknown'][]
key present, unverified  → skipped['unverified'][]
key verified, over quota → skipped['quota_exceeded'][]
key routed successfully  → bundle_ids[]
```

The sender's own key is silently excluded (unchanged behaviour — it is never an error).

### 3. Enriched `POST /bundles` response

The `data` object gains a `skipped` key. All three sub-arrays are always present.

```json
{
  "data": {
    "routed_to": 1,
    "bundle_ids": ["b8f3c2d1-..."],
    "skipped": {
      "unverified": [],
      "unknown": [],
      "quota_exceeded": []
    }
  }
}
```

Client guidance:
- `skipped.unverified` → "Waiting for recipient to verify their device"
- `skipped.unknown` → "Recipient has not registered with the relay"
- `skipped.quota_exceeded` → "Recipient's storage is full"

HTTP status remains 201 regardless of routing outcome.

### 4. API documentation

Update `docs/api.md`:
- Add `skipped` to the `POST /bundles` success response example and description.
- Note that the sender's own key is always excluded silently (not counted in `skipped`).

---

## Files changed

| File | Change |
|------|--------|
| `src/Repository/DeviceKeyRepository.php` | Add `findByKey()` |
| `src/Handler/Account/AddDeviceHandler.php` | Use `findByKey()` for duplicate check |
| `src/Handler/Bundle/DownloadBundleHandler.php` | Use `findByKey()` for ownership check |
| `src/Service/BundleRoutingService.php` | Use `findByKey()`, track and return `skipped` |
| `docs/api.md` | Document new `skipped` field |

---

## Out of scope

- Changing HTTP status from 201 when `routed_to: 0` (deferred per user decision).
- Queueing bundles for unverified keys (changes the storage model).
- Any other refactoring not directly required by the above fixes.

---

## Testing

- Unit test `findByKey` returns unverified rows (existing `findAccountByKey` test unchanged).
- Unit test routing returns correct `skipped` categories.
- Integration test: add unverified key, attempt re-add → 409 (not 500).
- Integration test: upload bundle to unverified recipient → 201 with `skipped.unverified` populated.
- Integration test: download bundle as legitimate owner with unverified key → 200 (not 403).
