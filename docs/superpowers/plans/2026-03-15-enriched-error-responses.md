# Enriched Error Responses Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix three silent-failure bugs caused by `findAccountByKey` (verified=1) being misused for ownership checks and duplicate detection, and enrich the `POST /bundles` response with a `skipped` breakdown.

**Architecture:** Add one new repository method (`DeviceKeyRepository::findByKey`), fix four call sites across three handlers and one service, add targeted tests for each bug. No schema changes; `UploadBundleHandler` requires no code change — it passes the routing result verbatim.

**Tech Stack:** PHP 8.3, Slim 4 (PSR-7 handlers), SQLite (:memory: for tests), PHPUnit 10, libsodium.

---

## Chunk 1: Repository method + AddDeviceHandler fix

### Task 1: Add `DeviceKeyRepository::findByKey()`

**Files:**
- Modify: `src/Repository/DeviceKeyRepository.php`
- Create: `tests/Unit/Repository/DeviceKeyRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Repository/DeviceKeyRepositoryTest.php`:

```php
<?php
declare(strict_types=1);
namespace Relay\Tests\Unit\Repository;
use PDO;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Repository\AccountRepository;
use Relay\Repository\DeviceKeyRepository;
final class DeviceKeyRepositoryTest extends TestCase
{
    private PDO $pdo;
    private DeviceKeyRepository $repo;
    private string $accountId;
    protected function setUp(): void
    {
        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();
        $this->repo = new DeviceKeyRepository($this->pdo);
        $accounts = new AccountRepository($this->pdo);
        $this->accountId = $accounts->create('test@example.com', 'hash', 'uuid-1');
    }
    public function test_findByKey_returns_null_for_unknown_key(): void
    {
        $result = $this->repo->findByKey('nonexistent_key');
        $this->assertNull($result);
    }
    public function test_findByKey_returns_row_for_verified_key(): void
    {
        $this->repo->add($this->accountId, 'verified_key_hex');
        $this->repo->markVerified('verified_key_hex');
        $result = $this->repo->findByKey('verified_key_hex');
        $this->assertNotNull($result);
        $this->assertSame('verified_key_hex', $result['device_public_key']);
        $this->assertSame($this->accountId, $result['account_id']);
        $this->assertSame(1, (int) $result['verified']);
    }
    public function test_findByKey_returns_row_for_unverified_key(): void
    {
        $this->repo->add($this->accountId, 'unverified_key_hex');
        // deliberately NOT calling markVerified
        $result = $this->repo->findByKey('unverified_key_hex');
        $this->assertNotNull($result);
        $this->assertSame('unverified_key_hex', $result['device_public_key']);
        $this->assertSame($this->accountId, $result['account_id']);
        $this->assertSame(0, (int) $result['verified']);
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit tests/Unit/Repository/DeviceKeyRepositoryTest.php --testdox
```

Expected: FAIL — `Call to undefined method Relay\Repository\DeviceKeyRepository::findByKey()`

- [ ] **Step 3: Implement `findByKey` in `DeviceKeyRepository`**

Add this method to `src/Repository/DeviceKeyRepository.php` after the existing `add()` method:

```php
public function findByKey(string $devicePublicKey): ?array
{
    $stmt = $this->pdo->prepare(
        'SELECT device_public_key, account_id, verified, added_at
         FROM device_keys WHERE device_public_key = ?'
    );
    $stmt->execute([$devicePublicKey]);
    return $stmt->fetch() ?: null;
}
```

- [ ] **Step 4: Run the test to confirm it passes**

```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit tests/Unit/Repository/DeviceKeyRepositoryTest.php --testdox
```

Expected: 3 tests, 3 assertions, PASS

- [ ] **Step 5: Run full suite to confirm no regressions**

```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --testdox
```

Expected: all existing tests still pass.

- [ ] **Step 6: Commit**

```bash
git add src/Repository/DeviceKeyRepository.php tests/Unit/Repository/DeviceKeyRepositoryTest.php
git commit -m "feat: add DeviceKeyRepository::findByKey() without verified constraint"
```

---

### Task 2: Fix `AddDeviceHandler` duplicate-key detection

The bug: `AddDeviceHandler` calls `findAccountByKey` (which requires `verified=1`) to detect duplicate keys. If the same key was previously added but not yet verified, `findAccountByKey` returns null — the INSERT proceeds and hits SQLite's UNIQUE constraint, producing an unhandled 500.

**Files:**
- Modify: `src/Handler/Account/AddDeviceHandler.php`
- Modify: `tests/Integration/Device/ProofOfPossessionTest.php`

- [ ] **Step 1: Write the failing integration test**

Add this test to the bottom of `tests/Integration/Device/ProofOfPossessionTest.php` (inside the class, before the closing `}`):

```php
public function test_add_duplicate_unverified_key_returns_409(): void
{
    $edKp = sodium_crypto_sign_keypair();
    $edPk = sodium_crypto_sign_publickey($edKp);
    $edPkHex = bin2hex($edPk);

    // Add the key but do NOT call markVerified — it stays unverified
    $this->deviceKeys->add($this->accountId, $edPkHex);

    $handler = new AddDeviceHandler(
        $this->deviceKeys,
        $this->challenges,
        $this->crypto,
        $this->settings,
    );

    $request = (new ServerRequestFactory())->createServerRequest('POST', '/account/devices')
        ->withAttribute('account_id', $this->accountId)
        ->withParsedBody(['device_public_key' => $edPkHex]);

    $response = $handler($request);

    $this->assertSame(409, $response->getStatusCode());
    $error = json_decode((string) $response->getBody(), true)['error'];
    $this->assertSame('KEY_EXISTS', $error['code']);
}
```

- [ ] **Step 2: Run the test to confirm it fails**

```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit tests/Integration/Device/ProofOfPossessionTest.php::test_add_duplicate_unverified_key_returns_409 --testdox
```

Expected: FAIL — either a PDO exception (500-style crash) or wrong status code.

- [ ] **Step 3: Fix the call site in `AddDeviceHandler`**

In `src/Handler/Account/AddDeviceHandler.php`, change line 35 from:

```php
$existing = $this->deviceKeys->findAccountByKey($devicePublicKey);
```

to:

```php
$existing = $this->deviceKeys->findByKey($devicePublicKey);
```

- [ ] **Step 4: Run the new test to confirm it passes**

```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit tests/Integration/Device/ProofOfPossessionTest.php --testdox
```

Expected: all tests in the file pass, including the new one.

- [ ] **Step 5: Run full suite**

```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --testdox
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/Handler/Account/AddDeviceHandler.php tests/Integration/Device/ProofOfPossessionTest.php
git commit -m "fix: return 409 KEY_EXISTS for unverified duplicate device keys"
```

---

## Chunk 2: Bundle ownership handler fixes

### Task 3: Fix ownership checks in `DownloadBundleHandler` and `DeleteBundleHandler`

The bug: both handlers call `findAccountByKey` (verified=1) to verify the requesting account owns the bundle. A legitimate recipient whose device key is unverified gets 403 on their own bundle.

**Files:**
- Create: `tests/Integration/Bundle/BundleOwnershipTest.php`
- Modify: `src/Handler/Bundle/DownloadBundleHandler.php`
- Modify: `src/Handler/Bundle/DeleteBundleHandler.php`

- [ ] **Step 1: Write the failing integration tests**

Create `tests/Integration/Bundle/BundleOwnershipTest.php`:

```php
<?php
declare(strict_types=1);
namespace Relay\Tests\Integration\Bundle;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Handler\Bundle\DeleteBundleHandler;
use Relay\Handler\Bundle\DownloadBundleHandler;
use Relay\Repository\AccountRepository;
use Relay\Repository\BundleRepository;
use Relay\Repository\DeviceKeyRepository;
use Relay\Service\StorageService;
use Slim\Psr7\Factory\ServerRequestFactory;
final class BundleOwnershipTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;
    private AccountRepository $accounts;
    private BundleRepository $bundles;
    private DeviceKeyRepository $deviceKeys;
    private StorageService $storage;

    protected function setUp(): void
    {
        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();
        $this->storagePath = sys_get_temp_dir() . '/relay_ownership_test_' . uniqid();
        mkdir($this->storagePath, 0777, true);
        $this->accounts  = new AccountRepository($this->pdo);
        $this->bundles   = new BundleRepository($this->pdo);
        $this->deviceKeys = new DeviceKeyRepository($this->pdo);
        $this->storage   = new StorageService($this->storagePath);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->storagePath);
    }

    /** Helper: create an account, add a device key (unverified), return [accountId, keyHex, bundleId]. */
    private function seedBundle(string $email, string $keyHex): array
    {
        $senderAccountId = $this->accounts->create('sender@test.com', 'hash', 'uuid-s');
        $this->deviceKeys->add($senderAccountId, 'sender_key_hex');

        $recipientAccountId = $this->accounts->create($email, 'hash', 'uuid-r');
        $this->deviceKeys->add($recipientAccountId, $keyHex);
        // deliberately NOT calling markVerified — key stays unverified

        $bundleId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $blobPath = $this->storage->store($bundleId, 'encrypted-payload');
        $this->bundles->createWithId(
            $bundleId,
            'ws-test',
            'sender_key_hex',
            $keyHex,
            'delta',
            strlen('encrypted-payload'),
            $blobPath
        );

        return [$recipientAccountId, $keyHex, $bundleId];
    }

    public function test_download_bundle_by_owner_with_unverified_key_returns_200(): void
    {
        [$accountId, , $bundleId] = $this->seedBundle('recipient@test.com', 'unverified_dl_key');

        $handler = new DownloadBundleHandler($this->bundles, $this->deviceKeys, $this->storage);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/bundles/' . $bundleId)
            ->withAttribute('account_id', $accountId)
            ->withAttribute('bundle_id', $bundleId);

        $response = $handler($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true)['data'];
        $this->assertSame($bundleId, $data['bundle_id']);
    }

    public function test_download_bundle_by_different_account_still_returns_403(): void
    {
        [, , $bundleId] = $this->seedBundle('recipient@test.com', 'unverified_dl_key2');
        $otherAccountId = $this->accounts->create('other@test.com', 'hash', 'uuid-other');

        $handler = new DownloadBundleHandler($this->bundles, $this->deviceKeys, $this->storage);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/bundles/' . $bundleId)
            ->withAttribute('account_id', $otherAccountId)
            ->withAttribute('bundle_id', $bundleId);

        $response = $handler($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('FORBIDDEN', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public function test_delete_bundle_by_owner_with_unverified_key_returns_200(): void
    {
        [$accountId, , $bundleId] = $this->seedBundle('recipient2@test.com', 'unverified_del_key');

        $handler = new DeleteBundleHandler($this->bundles, $this->deviceKeys, $this->accounts, $this->storage);
        $request = (new ServerRequestFactory())->createServerRequest('DELETE', '/bundles/' . $bundleId)
            ->withAttribute('account_id', $accountId)
            ->withAttribute('bundle_id', $bundleId);

        $response = $handler($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(json_decode((string) $response->getBody(), true)['data']['ok']);
    }

    public function test_delete_bundle_by_different_account_still_returns_403(): void
    {
        [, , $bundleId] = $this->seedBundle('recipient3@test.com', 'unverified_del_key2');
        $otherAccountId = $this->accounts->create('other2@test.com', 'hash', 'uuid-other2');

        $handler = new DeleteBundleHandler($this->bundles, $this->deviceKeys, $this->accounts, $this->storage);
        $request = (new ServerRequestFactory())->createServerRequest('DELETE', '/bundles/' . $bundleId)
            ->withAttribute('account_id', $otherAccountId)
            ->withAttribute('bundle_id', $bundleId);

        $response = $handler($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('FORBIDDEN', json_decode((string) $response->getBody(), true)['error']['code']);
    }
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit tests/Integration/Bundle/BundleOwnershipTest.php --testdox
```

Expected: `test_download_bundle_by_owner_with_unverified_key_returns_200` and `test_delete_bundle_by_owner_with_unverified_key_returns_200` FAIL with 403.

- [ ] **Step 3: Fix `DownloadBundleHandler`**

In `src/Handler/Bundle/DownloadBundleHandler.php`, change lines 25–28 from:

```php
$ownerCheck = $this->deviceKeys->findAccountByKey($bundle['recipient_device_key']);
if ($ownerCheck === null || $ownerCheck['account_id'] !== $accountId) {
    return $this->json(403, ['error' => ['code' => 'FORBIDDEN', 'message' => 'Not your bundle']]);
}
```

to:

```php
$ownerCheck = $this->deviceKeys->findByKey($bundle['recipient_device_key']);
if ($ownerCheck === null || $ownerCheck['account_id'] !== $accountId) {
    return $this->json(403, ['error' => ['code' => 'FORBIDDEN', 'message' => 'Not your bundle']]);
}
```

(Only the method name changes; the logic is identical.)

- [ ] **Step 4: Fix `DeleteBundleHandler`**

In `src/Handler/Bundle/DeleteBundleHandler.php`, change line 29 from:

```php
$ownerCheck = $this->deviceKeys->findAccountByKey($bundle['recipient_device_key']);
```

to:

```php
$ownerCheck = $this->deviceKeys->findByKey($bundle['recipient_device_key']);
```

- [ ] **Step 5: Run the new tests to confirm they pass**

```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit tests/Integration/Bundle/BundleOwnershipTest.php --testdox
```

Expected: 4 tests, 4 assertions, all PASS.

- [ ] **Step 6: Run full suite**

```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --testdox
```

Expected: all tests pass.

- [ ] **Step 7: Commit**

```bash
git add src/Handler/Bundle/DownloadBundleHandler.php src/Handler/Bundle/DeleteBundleHandler.php tests/Integration/Bundle/BundleOwnershipTest.php
git commit -m "fix: allow bundle download/delete by owners with unverified device keys"
```

---

## Chunk 3: Routing service enrichment + API docs

### Task 4: Enrich `BundleRoutingService` with `skipped` categorisation

The current routing loop silently skips recipients with a single `continue`. After this change it returns `skipped` arrays breaking down why each key was skipped, so `POST /bundles` clients have actionable data.

**Files:**
- Modify: `src/Service/BundleRoutingService.php`
- Modify: `tests/Integration/Bundle/UploadRouteTest.php`

- [ ] **Step 1: Write the failing tests**

Add these four tests to `tests/Integration/Bundle/UploadRouteTest.php` (inside the class, before the closing `}`):

```php
public function test_routing_unverified_recipient_appears_in_skipped_unverified(): void
{
    $accounts = new AccountRepository($this->pdo);
    $deviceKeys = new DeviceKeyRepository($this->pdo);
    $bundles = new BundleRepository($this->pdo);
    $storage = new StorageService($this->storagePath);

    $senderId = $accounts->create('sender@test.com', 'hash', 'uuid-s');
    $deviceKeys->add($senderId, 'sender_key');
    $deviceKeys->markVerified('sender_key');

    $recipientId = $accounts->create('recipient@test.com', 'hash', 'uuid-r');
    $deviceKeys->add($recipientId, 'unverified_key');
    // deliberately NOT calling markVerified

    $routing = new BundleRoutingService($bundles, $deviceKeys, $accounts, $storage);
    $header = json_encode([
        'workspace_id' => 'ws-001',
        'sender_device_key' => 'sender_key',
        'recipient_device_keys' => ['unverified_key'],
        'mode' => 'delta',
    ]);

    $result = $routing->routeBundle($header, 'payload');

    $this->assertSame(0, $result['routed_to']);
    $this->assertContains('unverified_key', $result['skipped']['unverified']);
    $this->assertEmpty($result['skipped']['unknown']);
    $this->assertEmpty($result['skipped']['quota_exceeded']);
}

public function test_routing_unknown_recipient_appears_in_skipped_unknown(): void
{
    $accounts = new AccountRepository($this->pdo);
    $deviceKeys = new DeviceKeyRepository($this->pdo);
    $bundles = new BundleRepository($this->pdo);
    $storage = new StorageService($this->storagePath);

    $senderId = $accounts->create('sender@test.com', 'hash', 'uuid-s');
    $deviceKeys->add($senderId, 'sender_key');
    $deviceKeys->markVerified('sender_key');

    $routing = new BundleRoutingService($bundles, $deviceKeys, $accounts, $storage);
    $header = json_encode([
        'workspace_id' => 'ws-001',
        'sender_device_key' => 'sender_key',
        'recipient_device_keys' => ['completely_unknown_key'],
        'mode' => 'delta',
    ]);

    $result = $routing->routeBundle($header, 'payload');

    $this->assertSame(0, $result['routed_to']);
    $this->assertContains('completely_unknown_key', $result['skipped']['unknown']);
    $this->assertEmpty($result['skipped']['unverified']);
    $this->assertEmpty($result['skipped']['quota_exceeded']);
}

public function test_routing_quota_exceeded_recipient_appears_in_skipped_quota_exceeded(): void
{
    $accounts = new AccountRepository($this->pdo);
    $deviceKeys = new DeviceKeyRepository($this->pdo);
    $bundles = new BundleRepository($this->pdo);
    $storage = new StorageService($this->storagePath);

    $senderId = $accounts->create('sender@test.com', 'hash', 'uuid-s');
    $deviceKeys->add($senderId, 'sender_key');
    $deviceKeys->markVerified('sender_key');

    $recipientId = $accounts->create('recipient@test.com', 'hash', 'uuid-r');
    $deviceKeys->add($recipientId, 'recipient_key');
    $deviceKeys->markVerified('recipient_key');

    // Set max storage to 1 byte — any payload will exceed it
    $routing = new BundleRoutingService($bundles, $deviceKeys, $accounts, $storage, 1);
    $header = json_encode([
        'workspace_id' => 'ws-001',
        'sender_device_key' => 'sender_key',
        'recipient_device_keys' => ['recipient_key'],
        'mode' => 'delta',
    ]);

    $result = $routing->routeBundle($header, 'payload-exceeds-one-byte');

    $this->assertSame(0, $result['routed_to']);
    $this->assertContains('recipient_key', $result['skipped']['quota_exceeded']);
    $this->assertEmpty($result['skipped']['unverified']);
    $this->assertEmpty($result['skipped']['unknown']);
}

public function test_routing_success_returns_empty_skipped_arrays(): void
{
    $accounts = new AccountRepository($this->pdo);
    $deviceKeys = new DeviceKeyRepository($this->pdo);
    $bundles = new BundleRepository($this->pdo);
    $storage = new StorageService($this->storagePath);

    $senderId = $accounts->create('sender@test.com', 'hash', 'uuid-s');
    $deviceKeys->add($senderId, 'sender_key');
    $deviceKeys->markVerified('sender_key');

    $recipientId = $accounts->create('recipient@test.com', 'hash', 'uuid-r');
    $deviceKeys->add($recipientId, 'recipient_key');
    $deviceKeys->markVerified('recipient_key');

    $routing = new BundleRoutingService($bundles, $deviceKeys, $accounts, $storage);
    $header = json_encode([
        'workspace_id' => 'ws-001',
        'sender_device_key' => 'sender_key',
        'recipient_device_keys' => ['recipient_key'],
        'mode' => 'delta',
    ]);

    $result = $routing->routeBundle($header, 'payload');

    $this->assertSame(1, $result['routed_to']);
    $this->assertCount(1, $result['bundle_ids']);
    $this->assertEmpty($result['skipped']['unverified']);
    $this->assertEmpty($result['skipped']['unknown']);
    $this->assertEmpty($result['skipped']['quota_exceeded']);
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit tests/Integration/Bundle/UploadRouteTest.php --testdox
```

Expected: the four new tests FAIL with `Undefined array key "skipped"`.

- [ ] **Step 3: Rewrite the `routeBundle` method**

Replace the entire `routeBundle` method in `src/Service/BundleRoutingService.php` with:

```php
public function routeBundle(string $headerJson, string $payloadData): array
{
    $header = json_decode($headerJson, true);
    if (!is_array($header) || empty($header['workspace_id']) || empty($header['sender_device_key']) || !is_array($header['recipient_device_keys'] ?? null)) {
        throw new \InvalidArgumentException('Invalid bundle header: workspace_id, sender_device_key, and recipient_device_keys[] are required');
    }
    $workspaceId = $header['workspace_id'];
    $senderKey = $header['sender_device_key'];
    $recipientKeys = $header['recipient_device_keys'];
    $mode = $header['mode'] ?? 'delta';
    $validModes = ['invite', 'accept', 'snapshot', 'delta'];
    if (!in_array($mode, $validModes, true)) {
        throw new \InvalidArgumentException("Invalid bundle mode: {$mode}");
    }
    $bundleIds = [];
    $skipped = ['unverified' => [], 'unknown' => [], 'quota_exceeded' => []];
    foreach ($recipientKeys as $recipientKey) {
        if ($recipientKey === $senderKey) { continue; }
        $keyRecord = $this->deviceKeys->findByKey($recipientKey);
        if ($keyRecord === null) { $skipped['unknown'][] = $recipientKey; continue; }
        if (!(bool) $keyRecord['verified']) { $skipped['unverified'][] = $recipientKey; continue; }
        $size = strlen($payloadData);
        $fullAccount = $this->accounts->findById($keyRecord['account_id']);
        $currentUsed = (int) ($fullAccount['storage_used'] ?? 0);
        if ($currentUsed + $size > $this->maxStoragePerAccountBytes) {
            $skipped['quota_exceeded'][] = $recipientKey;
            continue;
        }
        $bundleId = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $blobPath = $this->storage->store($bundleId, $payloadData);
        $this->bundles->createWithId($bundleId, $workspaceId, $senderKey, $recipientKey, $mode, $size, $blobPath);
        $this->accounts->updateStorageUsed($keyRecord['account_id'], $size);
        $bundleIds[] = $bundleId;
    }
    return ['routed_to' => count($bundleIds), 'bundle_ids' => $bundleIds, 'skipped' => $skipped];
}
```

- [ ] **Step 4: Run the new routing tests**

```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit tests/Integration/Bundle/UploadRouteTest.php --testdox
```

Expected: all 6 tests PASS (2 existing + 4 new).

- [ ] **Step 5: Run full suite**

```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --testdox
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

```bash
git add src/Service/BundleRoutingService.php tests/Integration/Bundle/UploadRouteTest.php
git commit -m "feat: enrich POST /bundles response with skipped recipient breakdown"
```

---

### Task 5: Update `docs/api.md`

**Files:**
- Modify: `docs/api.md`

- [ ] **Step 1: Replace the `POST /bundles` response example**

In `docs/api.md`, find the `POST /bundles` **Response `201`** block (around line 472). Replace:

```json
{
  "data": {
    "routed_to": 1,
    "bundle_ids": ["b8f3c2d1-..."]
  }
}
```

with:

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

- [ ] **Step 2: Add `skipped` field description**

Directly below the updated JSON block, replace the existing `routed_to` description line:

```
`routed_to` is the number of recipients a copy was created for. Recipients that exceed their storage quota are silently skipped.
```

with:

```
`routed_to` is the number of recipients a copy was created for. `skipped` is always present and contains three arrays of device keys that were not routed:

| Key | Meaning | Suggested client message |
|-----|---------|--------------------------|
| `skipped.unverified` | Key is registered but the owner has not completed device verification | "Waiting for recipient to verify their device" |
| `skipped.unknown` | Key is not registered with this relay | "Recipient has not registered with the relay" |
| `skipped.quota_exceeded` | Recipient's account has reached its storage limit | "Recipient's storage is full" |

The sender's own key is always excluded silently and is not counted in any `skipped` category.
```

- [ ] **Step 3: Commit**

```bash
git add docs/api.md
git commit -m "docs: document POST /bundles skipped field in API reference"
```

---

## Completion check

- [ ] Run the full test suite one final time:

```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --testdox
```

All tests should pass. The output should include the new test classes:
- `Relay\Tests\Unit\Repository\DeviceKeyRepositoryTest` (3 tests)
- `Relay\Tests\Integration\Device\ProofOfPossessionTest` (6 tests, +1 new)
- `Relay\Tests\Integration\Bundle\BundleOwnershipTest` (4 tests)
- `Relay\Tests\Integration\Bundle\UploadRouteTest` (6 tests, +4 new)
