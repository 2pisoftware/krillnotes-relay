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

Replace `findAccountByKey($devicePublicKey)` with `findByKey($devicePublicKey)`. An unverified key already in the database now correctly returns 409 `KEY_EXISTS` instead of a PDO exception. The 409 is the correct response regardless of which account owns the conflicting key — device public keys are globally unique in this relay and a collision is always an error from the submitter's perspective.

**`DownloadBundleHandler`** (`src/Handler/Bundle/DownloadBundleHandler.php`)

Replace `findAccountByKey($bundle['recipient_device_key'])` with `findByKey(...)`. Ownership check (`$row['account_id'] !== $accountId`) is logically identical but no longer fails for unverified recipient keys. If `findByKey` returns `null` (key row deleted after the bundle was stored), the 403 response is still correct — there is no legitimate owner to claim the bundle.

**`DeleteBundleHandler`** (`src/Handler/Bundle/DeleteBundleHandler.php`)

Identical ownership-check bug as `DownloadBundleHandler` — same fix applies. Replace `findAccountByKey($bundle['recipient_device_key'])` with `findByKey(...)` at line 29. If `findByKey` returns `null` (recipient key row was deleted after the bundle was stored), the 403 is still correct and the bundle remains in storage as an orphan; this edge case is pre-existing and out of scope for this change.

**`BundleRoutingService`** (`src/Service/BundleRoutingService.php`)

Replace `findAccountByKey($recipientKey)` with `findByKey($recipientKey)` and branch explicitly. `findByKey` returns the `device_keys` row which includes `account_id` as a native column of that table; this value is used for the subsequent `accounts->findById()` quota check, exactly as today.

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

### 4. API documentation (`docs/api.md`)

Replace the existing `POST /bundles` response example with the shape from Section 3. Add a description of the `skipped` field and its three sub-arrays beneath the example. Note that the sender's own key is always excluded silently and is not counted in any `skipped` category.

---

## Files changed

| File | Change |
|------|--------|
| `src/Repository/DeviceKeyRepository.php` | Add `findByKey()` |
| `src/Handler/Account/AddDeviceHandler.php` | Use `findByKey()` for duplicate check |
| `src/Handler/Bundle/DownloadBundleHandler.php` | Use `findByKey()` for ownership check |
| `src/Handler/Bundle/DeleteBundleHandler.php` | Use `findByKey()` for ownership check |
| `src/Service/BundleRoutingService.php` | Use `findByKey()`, track and return `skipped` |
| `src/Handler/Bundle/UploadBundleHandler.php` | No change required — already passes `$result` from `routeBundle()` verbatim as `data`; `skipped` appears automatically once the service adds it |
| `docs/api.md` | Document new `skipped` field |

---

## Out of scope

- Changing HTTP status from 201 when `routed_to: 0` (deferred per user decision).
- Queueing bundles for unverified keys (changes the storage model).
- Any other refactoring not directly required by the above fixes.

---

## Testing

All tests below are new additions to the suite.

- Unit: `findByKey` returns the row for an unverified key (existing `findAccountByKey` test unchanged).
- Unit: routing service returns correct `skipped` categories for unverified, unknown, and quota-exceeded recipients.
- Integration: add unverified key → re-submit the same key → expect 409 `KEY_EXISTS` (not 500).
- Integration: upload bundle to unverified recipient → expect 201 with `skipped.unverified` containing the recipient key.
- Integration: upload bundle to unknown recipient → expect 201 with `skipped.unknown` containing the recipient key.
- Integration: download bundle as legitimate owner whose device key is unverified → expect 200 (not 403).
- Integration: delete bundle as legitimate owner whose device key is unverified → expect 200 (not 403).
- Integration: upload bundle to a recipient whose account is at storage quota → expect 201 with `skipped.quota_exceeded` containing the recipient key.
