# Invite Workflow Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a public invite-link system: authenticated users upload an encrypted invite blob and get a shareable URL; anyone with the URL can download the blob without authenticating.

**Architecture:** A new `invites` table and `InviteRepository` handle persistence. Four handlers cover create/list/revoke (authenticated) and fetch (public, content-negotiated). A second `StorageService` instance keyed as `'InviteStorageService'` in the DI container handles blob storage under `storage/invites/`. The cleanup cron is extended to expire invite blobs.

**Tech Stack:** PHP 8.3, Slim 4, PHP-DI 7, SQLite (WAL via PDO), Ramsey UUID, PHPUnit 10

**Spec:** `docs/superpowers/specs/2026-03-14-invite-workflow-design.md`

---

## Chunk 1: Foundation — migration, repository, settings, container

### Task 1: Migration

**Files:**
- Create: `migrations/008_invites.sql`

- [ ] **Step 1: Create the migration file**

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

- [ ] **Step 2: Verify migration runs cleanly**

```bash
php bin/install.php
```

Expected: `Database created and migrations applied.` with no errors.

- [ ] **Step 3: Commit**

```bash
git add migrations/008_invites.sql
git commit -m "feat: add invites table migration"
```

---

### Task 2: Settings and storage path

**Files:**
- Modify: `config/settings.php`

- [ ] **Step 1: Add `invites_path` and `base_url` to settings**

In `config/settings.php`, add `base_url` at the top level and `invites_path` inside `storage`:

```php
return [
    'base_url' => 'https://swarm.krillnotes.org',
    'database' => [ /* unchanged */ ],
    'storage' => [
        'bundles_path' => dirname(__DIR__) . '/storage/bundles',
        'invites_path' => dirname(__DIR__) . '/storage/invites',
    ],
    'auth'   => [ /* unchanged */ ],
    'limits' => [ /* unchanged */ ],
];
```

- [ ] **Step 2: Commit**

```bash
git add config/settings.php
git commit -m "feat: add invites_path and base_url to settings"
```

---

### Task 3: InviteRepository

**Files:**
- Create: `src/Repository/InviteRepository.php`
- Create: `tests/Unit/Repository/InviteRepositoryTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Repository/InviteRepositoryTest.php`:

```php
<?php
declare(strict_types=1);
namespace Relay\Tests\Unit\Repository;
use PDO;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Repository\InviteRepository;

final class InviteRepositoryTest extends TestCase
{
    private PDO $pdo;
    private InviteRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();
        $this->pdo->exec("INSERT INTO accounts (account_id, email, password_hash, identity_uuid)
            VALUES ('acct-1', 'a@b.com', 'hash', 'uuid-1')");
        $this->repo = new InviteRepository($this->pdo);
    }

    public function test_create_and_find_by_token(): void
    {
        $this->repo->create('id-1', 'acct-1', 'tok123', '/path/blob', 100, '2099-01-01 00:00:00');
        $invite = $this->repo->findByToken('tok123');
        $this->assertNotNull($invite);
        $this->assertSame('id-1', $invite['invite_id']);
        $this->assertSame('acct-1', $invite['account_id']);
        $this->assertSame(0, (int) $invite['download_count']);
    }

    public function test_find_by_token_returns_null_for_unknown(): void
    {
        $this->assertNull($this->repo->findByToken('nope'));
    }

    public function test_list_for_account(): void
    {
        $this->repo->create('id-a', 'acct-1', 'tok-a', '/a', 10, '2099-01-01 00:00:00');
        $this->repo->create('id-b', 'acct-1', 'tok-b', '/b', 20, '2099-01-01 00:00:00');
        $this->assertCount(2, $this->repo->listForAccount('acct-1'));
    }

    public function test_increment_download_count(): void
    {
        $this->repo->create('id-1', 'acct-1', 'tok123', '/path', 100, '2099-01-01 00:00:00');
        $this->repo->incrementDownloadCount('tok123');
        $this->repo->incrementDownloadCount('tok123');
        $this->assertSame(2, (int) $this->repo->findByToken('tok123')['download_count']);
    }

    public function test_delete(): void
    {
        $this->repo->create('id-1', 'acct-1', 'tok123', '/path/blob', 100, '2099-01-01 00:00:00');
        $blobPath = $this->repo->delete('tok123');
        $this->assertSame('/path/blob', $blobPath);
        $this->assertNull($this->repo->findByToken('tok123'));
    }

    public function test_delete_returns_null_for_unknown(): void
    {
        $this->assertNull($this->repo->delete('nope'));
    }

    public function test_delete_expired_before_returns_rows_without_deleting(): void
    {
        $this->repo->create('id-p', 'acct-1', 'past',   '/past',   10, '2000-01-01 00:00:00');
        $this->repo->create('id-f', 'acct-1', 'future', '/future', 10, '2099-01-01 00:00:00');
        $expired = $this->repo->deleteExpiredBefore(date('Y-m-d H:i:s'));
        $this->assertCount(1, $expired);
        $this->assertSame('/past', $expired[0]['blob_path']);
        // Row is NOT deleted yet — caller deletes blobs first, then calls deleteByIds
        $this->assertNotNull($this->repo->findByToken('past'));
        $this->assertNotNull($this->repo->findByToken('future'));
    }

    public function test_delete_by_ids_removes_rows(): void
    {
        $this->repo->create('id-p', 'acct-1', 'past',   '/past',   10, '2000-01-01 00:00:00');
        $this->repo->create('id-f', 'acct-1', 'future', '/future', 10, '2099-01-01 00:00:00');
        $this->repo->deleteByIds(['id-p']);
        $this->assertNull($this->repo->findByToken('past'));
        $this->assertNotNull($this->repo->findByToken('future'));
    }
}
```

- [ ] **Step 2: Run tests — verify they fail**

```bash
php vendor/bin/phpunit tests/Unit/Repository/InviteRepositoryTest.php
```

Expected: FAIL — class `InviteRepository` not found.

- [ ] **Step 3: Implement InviteRepository**

`src/Repository/InviteRepository.php`:

```php
<?php
declare(strict_types=1);
namespace Relay\Repository;
use PDO;

final class InviteRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(string $inviteId, string $accountId, string $token, string $blobPath, int $sizeBytes, string $expiresAt): void
    {
        $this->pdo->prepare(
            'INSERT INTO invites (invite_id, token, account_id, blob_path, size_bytes, expires_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$inviteId, $token, $accountId, $blobPath, $sizeBytes, $expiresAt]);
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invites WHERE token = ?');
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    public function listForAccount(string $accountId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invites WHERE account_id = ? ORDER BY created_at DESC');
        $stmt->execute([$accountId]);
        return $stmt->fetchAll();
    }

    public function incrementDownloadCount(string $token): void
    {
        $this->pdo->prepare('UPDATE invites SET download_count = download_count + 1 WHERE token = ?')
            ->execute([$token]);
    }

    public function delete(string $token): ?string
    {
        $stmt = $this->pdo->prepare('SELECT blob_path FROM invites WHERE token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) { return null; }
        $this->pdo->prepare('DELETE FROM invites WHERE token = ?')->execute([$token]);
        return $row['blob_path'];
    }

    public function deleteExpiredBefore(string $now): array
    {
        // Returns expired rows only — does NOT delete. Caller deletes blobs first, then calls deleteByIds.
        $stmt = $this->pdo->prepare('SELECT invite_id, blob_path FROM invites WHERE expires_at < ?');
        $stmt->execute([$now]);
        return $stmt->fetchAll();
    }

    public function deleteByIds(array $ids): void
    {
        if (empty($ids)) { return; }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->prepare("DELETE FROM invites WHERE invite_id IN ({$placeholders})")->execute($ids);
    }
}
```

- [ ] **Step 4: Run tests — verify they pass**

```bash
php vendor/bin/phpunit tests/Unit/Repository/InviteRepositoryTest.php
```

Expected: 8 tests, 0 failures.

- [ ] **Step 5: Commit**

```bash
git add src/Repository/InviteRepository.php tests/Unit/Repository/InviteRepositoryTest.php
git commit -m "feat: InviteRepository with full test coverage"
```

---

### Task 4: Wire container and install

**Files:**
- Modify: `config/container.php`
- Modify: `bin/install.php`

- [ ] **Step 1: Add InviteStorageService to container**

In `config/container.php`, after the existing `StorageService::class` binding, add:

```php
'InviteStorageService' => function ($c) {
    return new StorageService($c->get('settings')['storage']['invites_path']);
},

\Relay\Handler\Invite\CreateInviteHandler::class => function ($c) {
    return new \Relay\Handler\Invite\CreateInviteHandler(
        $c->get(\Relay\Repository\InviteRepository::class),
        $c->get('InviteStorageService'),
        $c->get('settings'),
    );
},

\Relay\Handler\Invite\ListInvitesHandler::class => function ($c) {
    return new \Relay\Handler\Invite\ListInvitesHandler(
        $c->get(\Relay\Repository\InviteRepository::class),
        $c->get('settings'),
    );
},

\Relay\Handler\Invite\RevokeInviteHandler::class => function ($c) {
    return new \Relay\Handler\Invite\RevokeInviteHandler(
        $c->get(\Relay\Repository\InviteRepository::class),
        $c->get('InviteStorageService'),
    );
},

\Relay\Handler\Invite\FetchInviteHandler::class => function ($c) {
    return new \Relay\Handler\Invite\FetchInviteHandler(
        $c->get(\Relay\Repository\InviteRepository::class),
        $c->get('InviteStorageService'),
        $c->get('settings'),
    );
},
```

- [ ] **Step 2: Add storage/invites/ to bin/install.php**

In `bin/install.php`, add `invites_path` to the `$dirs` array:

```php
$dirs = [
    dirname($settings['database']['path']),
    $settings['storage']['bundles_path'],
    $settings['storage']['invites_path'],
];
```

- [ ] **Step 3: Run install to verify no errors**

```bash
php bin/install.php
```

Expected: `storage/invites/` created or already exists, no errors.

- [ ] **Step 4: Commit**

```bash
git add config/container.php bin/install.php
git commit -m "feat: wire InviteStorageService and invite handlers in container"
```

---

## Chunk 2: Create and List handlers

### Task 5: CreateInviteHandler

**Files:**
- Create: `src/Handler/Invite/CreateInviteHandler.php`
- Create: `tests/Integration/Invite/CreateInviteTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Integration/Invite/CreateInviteTest.php`:

```php
<?php
declare(strict_types=1);
namespace Relay\Tests\Integration\Invite;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Handler\Invite\CreateInviteHandler;
use Relay\Repository\AccountRepository;
use Relay\Repository\InviteRepository;
use Relay\Service\StorageService;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CreateInviteTest extends TestCase
{
    private \PDO $pdo;
    private array $settings;
    private string $invitesDir;
    private string $accountId;

    protected function setUp(): void
    {
        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();
        $this->settings = require dirname(__DIR__, 3) . '/config/settings.php';
        $this->invitesDir = sys_get_temp_dir() . '/relay_test_invites_' . uniqid();
        $this->settings['storage']['invites_path'] = $this->invitesDir;
        (new AccountRepository($this->pdo))->create('alice@example.com', password_hash('pw', PASSWORD_BCRYPT), 'uuid-alice');
        $this->accountId = (new AccountRepository($this->pdo))->findByEmail('alice@example.com')['account_id'];
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->invitesDir}/*/*") ?: [] as $f) { unlink($f); }
        foreach (glob("{$this->invitesDir}/*") ?: [] as $d) { if (is_dir($d)) rmdir($d); }
        if (is_dir($this->invitesDir)) { rmdir($this->invitesDir); }
    }

    private function makeHandler(): CreateInviteHandler
    {
        return new CreateInviteHandler(
            new InviteRepository($this->pdo),
            new StorageService($this->invitesDir),
            $this->settings,
        );
    }

    public function test_create_invite_returns_201_with_token_and_url(): void
    {
        $expiresAt = date('Y-m-d\TH:i:s\Z', strtotime('+7 days'));
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/invites')
            ->withAttribute('account_id', $this->accountId)
            ->withParsedBody(['payload' => base64_encode('encrypted-data'), 'expires_at' => $expiresAt]);
        $response = $this->makeHandler()($request);
        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true)['data'];
        $this->assertNotEmpty($data['invite_id']);
        $this->assertSame(64, strlen($data['token']));
        $this->assertStringContainsString('/invites/', $data['url']);
        $this->assertSame($expiresAt, $data['expires_at']);
    }

    public function test_missing_payload_returns_400(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/invites')
            ->withAttribute('account_id', $this->accountId)
            ->withParsedBody(['expires_at' => date('Y-m-d\TH:i:s\Z', strtotime('+1 day'))]);
        $response = $this->makeHandler()($request);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('MISSING_FIELDS', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public function test_invalid_base64_returns_400(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/invites')
            ->withAttribute('account_id', $this->accountId)
            ->withParsedBody(['payload' => '!!!not-base64!!!', 'expires_at' => date('Y-m-d\TH:i:s\Z', strtotime('+1 day'))]);
        $response = $this->makeHandler()($request);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('INVALID_PAYLOAD', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public function test_expires_at_in_past_returns_400(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/invites')
            ->withAttribute('account_id', $this->accountId)
            ->withParsedBody(['payload' => base64_encode('data'), 'expires_at' => '2000-01-01T00:00:00Z']);
        $response = $this->makeHandler()($request);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('INVALID_EXPIRY', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public function test_expires_at_beyond_90_days_returns_400(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/invites')
            ->withAttribute('account_id', $this->accountId)
            ->withParsedBody(['payload' => base64_encode('data'), 'expires_at' => date('Y-m-d\TH:i:s\Z', strtotime('+91 days'))]);
        $response = $this->makeHandler()($request);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('INVALID_EXPIRY', json_decode((string) $response->getBody(), true)['error']['code']);
    }
}
```

- [ ] **Step 2: Run tests — verify they fail**

```bash
php vendor/bin/phpunit tests/Integration/Invite/CreateInviteTest.php
```

Expected: FAIL — class `CreateInviteHandler` not found.

- [ ] **Step 3: Implement CreateInviteHandler**

`src/Handler/Invite/CreateInviteHandler.php`:

```php
<?php
declare(strict_types=1);
namespace Relay\Handler\Invite;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Relay\Repository\InviteRepository;
use Relay\Service\StorageService;
use Slim\Psr7\Response;

final class CreateInviteHandler
{
    public function __construct(
        private readonly InviteRepository $invites,
        private readonly StorageService $storage,
        private readonly array $settings,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body      = $request->getParsedBody();
        $accountId = $request->getAttribute('account_id');
        $payload   = $body['payload'] ?? '';
        $expiresAt = $body['expires_at'] ?? '';

        if (!$payload || !$expiresAt) {
            return $this->json(400, ['error' => ['code' => 'MISSING_FIELDS', 'message' => 'payload and expires_at are required']]);
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return $this->json(400, ['error' => ['code' => 'INVALID_PAYLOAD', 'message' => 'payload must be valid base64']]);
        }

        $maxSize = $this->settings['limits']['max_bundle_size_bytes'];
        if (strlen($decoded) > $maxSize) {
            return $this->json(413, ['error' => ['code' => 'PAYLOAD_TOO_LARGE', 'message' => "Payload exceeds {$maxSize} bytes"]]);
        }

        $expireTs = strtotime($expiresAt);
        if ($expireTs === false || $expireTs <= time()) {
            return $this->json(400, ['error' => ['code' => 'INVALID_EXPIRY', 'message' => 'expires_at must be a future ISO 8601 datetime']]);
        }
        if ($expireTs > strtotime('+90 days')) {
            return $this->json(400, ['error' => ['code' => 'INVALID_EXPIRY', 'message' => 'expires_at cannot be more than 90 days from now']]);
        }

        $token    = bin2hex(random_bytes(32));
        $inviteId = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $blobPath = $this->storage->store($inviteId, $decoded);
        $dbExpiry = date('Y-m-d H:i:s', $expireTs);

        $this->invites->create($inviteId, $accountId, $token, $blobPath, strlen($decoded), $dbExpiry);

        $baseUrl = rtrim($this->settings['base_url'], '/');
        return $this->json(201, ['data' => [
            'invite_id'  => $inviteId,
            'token'      => $token,
            'url'        => "{$baseUrl}/invites/{$token}",
            'expires_at' => $expiresAt,
        ]]);
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

- [ ] **Step 4: Run tests — verify they pass**

```bash
php vendor/bin/phpunit tests/Integration/Invite/CreateInviteTest.php
```

Expected: 5 tests, 0 failures.

- [ ] **Step 5: Commit**

```bash
git add src/Handler/Invite/CreateInviteHandler.php tests/Integration/Invite/CreateInviteTest.php
git commit -m "feat: CreateInviteHandler with validation and blob storage"
```

---

### Task 6: ListInvitesHandler

**Files:**
- Create: `src/Handler/Invite/ListInvitesHandler.php`
- Create: `tests/Integration/Invite/ListInvitesTest.php`

- [ ] **Step 1: Write the failing test**

`tests/Integration/Invite/ListInvitesTest.php`:

```php
<?php
declare(strict_types=1);
namespace Relay\Tests\Integration\Invite;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Handler\Invite\ListInvitesHandler;
use Relay\Repository\AccountRepository;
use Relay\Repository\InviteRepository;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ListInvitesTest extends TestCase
{
    private \PDO $pdo;
    private string $accountId;

    protected function setUp(): void
    {
        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();
        (new AccountRepository($this->pdo))->create('alice@example.com', 'hash', 'uuid');
        $this->accountId = (new AccountRepository($this->pdo))->findByEmail('alice@example.com')['account_id'];
        $repo = new InviteRepository($this->pdo);
        $repo->create('id-a', $this->accountId, 'tok-a', '/a', 10, '2099-01-01 00:00:00');
        $repo->create('id-b', $this->accountId, 'tok-b', '/b', 20, '2099-06-01 00:00:00');
    }

    public function test_list_returns_own_invites_with_url(): void
    {
        $settings = require dirname(__DIR__, 3) . '/config/settings.php';
        $handler  = new ListInvitesHandler(new InviteRepository($this->pdo), $settings);
        $request  = (new ServerRequestFactory())
            ->createServerRequest('GET', '/invites')
            ->withAttribute('account_id', $this->accountId);
        $response = $handler($request);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true)['data'];
        $this->assertCount(2, $data);
        $this->assertArrayHasKey('url', $data[0]);
        $this->assertStringContainsString('/invites/', $data[0]['url']);
        $this->assertArrayHasKey('download_count', $data[0]);
    }

    public function test_list_returns_empty_array_when_no_invites(): void
    {
        $settings = require dirname(__DIR__, 3) . '/config/settings.php';
        // Create a second account with no invites
        (new AccountRepository($this->pdo))->create('bob@example.com', 'hash', 'uuid2');
        $bobId    = (new AccountRepository($this->pdo))->findByEmail('bob@example.com')['account_id'];
        $handler  = new ListInvitesHandler(new InviteRepository($this->pdo), $settings);
        $request  = (new ServerRequestFactory())
            ->createServerRequest('GET', '/invites')
            ->withAttribute('account_id', $bobId);
        $response = $handler($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], json_decode((string) $response->getBody(), true)['data']);
    }

    public function test_list_does_not_return_other_accounts_invites(): void
    {
        $settings = require dirname(__DIR__, 3) . '/config/settings.php';
        (new AccountRepository($this->pdo))->create('bob@example.com', 'hash', 'uuid2');
        $bobId = (new AccountRepository($this->pdo))->findByEmail('bob@example.com')['account_id'];
        (new InviteRepository($this->pdo))->create('id-bob', $bobId, 'tok-bob', '/bob', 5, '2099-01-01 00:00:00');
        $handler  = new ListInvitesHandler(new InviteRepository($this->pdo), $settings);
        $request  = (new ServerRequestFactory())
            ->createServerRequest('GET', '/invites')
            ->withAttribute('account_id', $this->accountId);
        $data = json_decode((string) $handler($request)->getBody(), true)['data'];
        // Alice still sees exactly 2 (her own), not Bob's
        $this->assertCount(2, $data);
        $tokens = array_column($data, 'token');
        $this->assertNotContains('tok-bob', $tokens);
    }
}
```

- [ ] **Step 2: Run test — verify it fails**

```bash
php vendor/bin/phpunit tests/Integration/Invite/ListInvitesTest.php
```

Expected: FAIL — class `ListInvitesHandler` not found.

- [ ] **Step 3: Implement ListInvitesHandler**

`src/Handler/Invite/ListInvitesHandler.php`:

```php
<?php
declare(strict_types=1);
namespace Relay\Handler\Invite;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Relay\Repository\InviteRepository;
use Slim\Psr7\Response;

final class ListInvitesHandler
{
    public function __construct(
        private readonly InviteRepository $invites,
        private readonly array $settings,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $accountId = $request->getAttribute('account_id');
        $baseUrl   = rtrim($this->settings['base_url'], '/');
        $rows      = $this->invites->listForAccount($accountId);

        $data = array_map(fn($row) => [
            'invite_id'      => $row['invite_id'],
            'token'          => $row['token'],
            'url'            => "{$baseUrl}/invites/{$row['token']}",
            'expires_at'     => $row['expires_at'],
            'download_count' => (int) $row['download_count'],
            'created_at'     => $row['created_at'],
        ], $rows);

        $response = new Response(200);
        $response->getBody()->write(json_encode(['data' => $data]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

- [ ] **Step 4: Run test — verify it passes**

```bash
php vendor/bin/phpunit tests/Integration/Invite/ListInvitesTest.php
```

Expected: 3 tests, 0 failures.

- [ ] **Step 5: Commit**

```bash
git add src/Handler/Invite/ListInvitesHandler.php tests/Integration/Invite/ListInvitesTest.php
git commit -m "feat: ListInvitesHandler"
```

---

## Chunk 3: Revoke, Fetch, and Routes

### Task 7: RevokeInviteHandler

**Files:**
- Create: `src/Handler/Invite/RevokeInviteHandler.php`
- Create: `tests/Integration/Invite/RevokeInviteTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Integration/Invite/RevokeInviteTest.php`:

```php
<?php
declare(strict_types=1);
namespace Relay\Tests\Integration\Invite;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Handler\Invite\RevokeInviteHandler;
use Relay\Repository\AccountRepository;
use Relay\Repository\InviteRepository;
use Relay\Service\StorageService;
use Slim\Psr7\Factory\ServerRequestFactory;

final class RevokeInviteTest extends TestCase
{
    private \PDO $pdo;
    private string $accountId;
    private string $invitesDir;
    private InviteRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();
        (new AccountRepository($this->pdo))->create('alice@example.com', 'hash', 'uuid');
        $this->accountId = (new AccountRepository($this->pdo))->findByEmail('alice@example.com')['account_id'];
        $this->invitesDir = sys_get_temp_dir() . '/relay_revoke_' . uniqid();
        mkdir($this->invitesDir, 0750, true);
        $this->repo = new InviteRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->invitesDir}/*/*") ?: [] as $f) { unlink($f); }
        foreach (glob("{$this->invitesDir}/*") ?: [] as $d) { if (is_dir($d)) rmdir($d); }
        if (is_dir($this->invitesDir)) { rmdir($this->invitesDir); }
    }

    private function makeHandler(): RevokeInviteHandler
    {
        return new RevokeInviteHandler($this->repo, new StorageService($this->invitesDir));
    }

    public function test_revoke_deletes_invite_and_returns_ok(): void
    {
        $storage  = new StorageService($this->invitesDir);
        $blobPath = $storage->store('fake-id-abc', 'blob-data');
        $this->repo->create('id-revoke', $this->accountId, 'tok-revoke', $blobPath, 9, '2099-01-01 00:00:00');

        $request = (new ServerRequestFactory())
            ->createServerRequest('DELETE', '/invites/tok-revoke')
            ->withAttribute('account_id', $this->accountId)
            ->withAttribute('token', 'tok-revoke');
        $response = $this->makeHandler()($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(json_decode((string) $response->getBody(), true)['data']['ok']);
        $this->assertNull($this->repo->findByToken('tok-revoke'));
        $this->assertFileDoesNotExist($blobPath);
    }

    public function test_revoke_unknown_token_returns_404(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('DELETE', '/invites/nope')
            ->withAttribute('account_id', $this->accountId)
            ->withAttribute('token', 'nope');
        $this->assertSame(404, $this->makeHandler()($request)->getStatusCode());
    }

    public function test_revoke_by_non_owner_returns_403(): void
    {
        $this->repo->create('id-owned', $this->accountId, 'tok-owned', '/path', 10, '2099-01-01 00:00:00');
        $request = (new ServerRequestFactory())
            ->createServerRequest('DELETE', '/invites/tok-owned')
            ->withAttribute('account_id', 'other-account-id')
            ->withAttribute('token', 'tok-owned');
        $this->assertSame(403, $this->makeHandler()($request)->getStatusCode());
    }
}
```

- [ ] **Step 2: Run tests — verify they fail**

```bash
php vendor/bin/phpunit tests/Integration/Invite/RevokeInviteTest.php
```

Expected: FAIL — class `RevokeInviteHandler` not found.

- [ ] **Step 3: Implement RevokeInviteHandler**

`src/Handler/Invite/RevokeInviteHandler.php`:

```php
<?php
declare(strict_types=1);
namespace Relay\Handler\Invite;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Relay\Repository\InviteRepository;
use Relay\Service\StorageService;
use Slim\Psr7\Response;

final class RevokeInviteHandler
{
    public function __construct(
        private readonly InviteRepository $invites,
        private readonly StorageService $storage,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $accountId = $request->getAttribute('account_id');
        $token     = $request->getAttribute('token');
        $invite    = $this->invites->findByToken($token);

        if ($invite === null) {
            return $this->json(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'Invite not found']]);
        }
        if ($invite['account_id'] !== $accountId) {
            return $this->json(403, ['error' => ['code' => 'FORBIDDEN', 'message' => 'Not your invite']]);
        }

        $blobPath = $this->invites->delete($token);
        if ($blobPath !== null) {
            $this->storage->delete($blobPath);
        }

        return $this->json(200, ['data' => ['ok' => true]]);
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

- [ ] **Step 4: Run tests — verify they pass**

```bash
php vendor/bin/phpunit tests/Integration/Invite/RevokeInviteTest.php
```

Expected: 3 tests, 0 failures.

- [ ] **Step 5: Commit**

```bash
git add src/Handler/Invite/RevokeInviteHandler.php tests/Integration/Invite/RevokeInviteTest.php
git commit -m "feat: RevokeInviteHandler with ownership check and blob deletion"
```

---

### Task 8: FetchInviteHandler

**Files:**
- Create: `src/Handler/Invite/FetchInviteHandler.php`
- Create: `tests/Integration/Invite/FetchInviteTest.php`

- [ ] **Step 1: Write the failing tests**

`tests/Integration/Invite/FetchInviteTest.php`:

```php
<?php
declare(strict_types=1);
namespace Relay\Tests\Integration\Invite;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Handler\Invite\FetchInviteHandler;
use Relay\Repository\AccountRepository;
use Relay\Repository\InviteRepository;
use Relay\Service\StorageService;
use Slim\Psr7\Factory\ServerRequestFactory;

final class FetchInviteTest extends TestCase
{
    private \PDO $pdo;
    private array $settings;
    private string $invitesDir;
    private string $accountId;
    private InviteRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();
        $this->settings = require dirname(__DIR__, 3) . '/config/settings.php';
        $this->invitesDir = sys_get_temp_dir() . '/relay_fetch_' . uniqid();
        $this->settings['storage']['invites_path'] = $this->invitesDir;
        (new AccountRepository($this->pdo))->create('alice@example.com', 'hash', 'uuid');
        $this->accountId = (new AccountRepository($this->pdo))->findByEmail('alice@example.com')['account_id'];
        $this->repo = new InviteRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->invitesDir}/*/*") ?: [] as $f) { unlink($f); }
        foreach (glob("{$this->invitesDir}/*") ?: [] as $d) { if (is_dir($d)) rmdir($d); }
        if (is_dir($this->invitesDir)) { rmdir($this->invitesDir); }
    }

    private function makeHandler(): FetchInviteHandler
    {
        return new FetchInviteHandler($this->repo, new StorageService($this->invitesDir), $this->settings);
    }

    private function seedInvite(string $token, string $expiresAt): void
    {
        $storage  = new StorageService($this->invitesDir);
        $blobPath = $storage->store('inv-' . $token, 'secret-encrypted-data');
        $this->repo->create('inv-' . $token, $this->accountId, $token, $blobPath, 21, $expiresAt);
    }

    public function test_json_accept_returns_base64_payload(): void
    {
        $this->seedInvite('valid-tok', '2099-01-01 00:00:00');
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/invites/valid-tok')
            ->withAttribute('token', 'valid-tok')
            ->withHeader('Accept', 'application/json');
        $response = $this->makeHandler()($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode((string) $response->getBody(), true)['data'];
        $this->assertSame(base64_encode('secret-encrypted-data'), $data['payload']);
        $this->assertArrayHasKey('expires_at', $data);
    }

    public function test_json_fetch_increments_download_count(): void
    {
        $this->seedInvite('count-tok', '2099-01-01 00:00:00');
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/invites/count-tok')
            ->withAttribute('token', 'count-tok')
            ->withHeader('Accept', 'application/json');
        $this->makeHandler()($request);
        $this->assertSame(1, (int) $this->repo->findByToken('count-tok')['download_count']);
    }

    public function test_browser_accept_returns_html_with_invite_url(): void
    {
        $this->seedInvite('html-tok', '2099-01-01 00:00:00');
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/invites/html-tok')
            ->withAttribute('token', 'html-tok')
            ->withHeader('Accept', 'text/html,application/xhtml+xml,*/*');
        $response = $this->makeHandler()($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        $this->assertStringContainsString('KrillNotes', $body);
        $this->assertStringContainsString('html-tok', $body);
    }

    public function test_html_fetch_does_not_increment_download_count(): void
    {
        $this->seedInvite('html-count', '2099-01-01 00:00:00');
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/invites/html-count')
            ->withAttribute('token', 'html-count')
            ->withHeader('Accept', 'text/html,*/*');
        $this->makeHandler()($request);
        $this->assertSame(0, (int) $this->repo->findByToken('html-count')['download_count']);
    }

    public function test_unknown_token_returns_404(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/invites/nope')
            ->withAttribute('token', 'nope')
            ->withHeader('Accept', 'application/json');
        $this->assertSame(404, $this->makeHandler()($request)->getStatusCode());
    }

    public function test_expired_invite_returns_410(): void
    {
        $this->seedInvite('expired-tok', '2000-01-01 00:00:00');
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/invites/expired-tok')
            ->withAttribute('token', 'expired-tok')
            ->withHeader('Accept', 'application/json');
        $response = $this->makeHandler()($request);
        $this->assertSame(410, $response->getStatusCode());
        $this->assertSame('GONE', json_decode((string) $response->getBody(), true)['error']['code']);
    }
}
```

- [ ] **Step 2: Run tests — verify they fail**

```bash
php vendor/bin/phpunit tests/Integration/Invite/FetchInviteTest.php
```

Expected: FAIL — class `FetchInviteHandler` not found.

- [ ] **Step 3: Implement FetchInviteHandler**

`src/Handler/Invite/FetchInviteHandler.php`:

```php
<?php
declare(strict_types=1);
namespace Relay\Handler\Invite;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Relay\Repository\InviteRepository;
use Relay\Service\StorageService;
use Slim\Psr7\Response;

final class FetchInviteHandler
{
    public function __construct(
        private readonly InviteRepository $invites,
        private readonly StorageService $storage,
        private readonly array $settings,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $token  = $request->getAttribute('token');
        $invite = $this->invites->findByToken($token);
        $wantsJson = str_contains($request->getHeaderLine('Accept'), 'application/json');

        if ($invite === null) {
            return $wantsJson
                ? $this->json(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'Invite not found']])
                : $this->html(404, $this->expiredHtml());
        }

        if (strtotime($invite['expires_at']) <= time()) {
            return $wantsJson
                ? $this->json(410, ['error' => ['code' => 'GONE', 'message' => 'This invite has expired']])
                : $this->html(410, $this->expiredHtml());
        }

        if ($wantsJson) {
            $blob = $this->storage->read($invite['blob_path']);
            if ($blob === null) {
                return $this->json(410, ['error' => ['code' => 'GONE', 'message' => 'Invite payload not found']]);
            }
            $this->invites->incrementDownloadCount($token);
            $response = new Response(200);
            $response->getBody()->write(json_encode(['data' => [
                'payload'    => base64_encode($blob),
                'expires_at' => $invite['expires_at'],
            ]]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $baseUrl = rtrim($this->settings['base_url'], '/');
        $url     = htmlspecialchars("{$baseUrl}/invites/{$token}");
        return $this->html(200, <<<HTML
            <!doctype html><html lang="en"><head><meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>KrillNotes Invite</title></head><body>
            <h1>You've been invited to a KrillNotes workspace</h1>
            <p>To accept this invite, open it in the KrillNotes app.</p>
            <p>If you don't have KrillNotes yet, download it at
            <a href="https://krillnotes.com">krillnotes.com</a>.</p>
            <p>Your invite URL:<br><code>{$url}</code></p>
            </body></html>
            HTML);
    }

    private function expiredHtml(): string
    {
        return <<<HTML
            <!doctype html><html lang="en"><head><meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>Invite No Longer Valid</title></head><body>
            <h1>This invite is no longer valid</h1>
            <p>The invite link has expired or been revoked.</p>
            </body></html>
            HTML;
    }

    private function html(int $status, string $body): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
```

- [ ] **Step 4: Run tests — verify they pass**

```bash
php vendor/bin/phpunit tests/Integration/Invite/FetchInviteTest.php
```

Expected: 6 tests, 0 failures.

- [ ] **Step 5: Commit**

```bash
git add src/Handler/Invite/FetchInviteHandler.php tests/Integration/Invite/FetchInviteTest.php
git commit -m "feat: FetchInviteHandler with JSON/HTML content negotiation"
```

---

### Task 9: Wire routes

**Files:**
- Modify: `config/routes.php`

- [ ] **Step 1: Add invite routes**

In `config/routes.php`:

Add the public fetch route **before** the authenticated group:

```php
$app->get('/invites/{token}', \Relay\Handler\Invite\FetchInviteHandler::class);
```

Add the three authenticated routes **inside** the `$app->group('', ...)` closure, alongside the existing mailbox and bundle routes:

```php
$group->post('/invites',           \Relay\Handler\Invite\CreateInviteHandler::class);
$group->get('/invites',            \Relay\Handler\Invite\ListInvitesHandler::class);
$group->delete('/invites/{token}', \Relay\Handler\Invite\RevokeInviteHandler::class);
```

- [ ] **Step 2: Smoke-test routes on dev server**

```bash
php -S localhost:8080 -t public/ public/index.php > /tmp/relay.log 2>&1 &
sleep 1

# Public fetch unknown token — expect JSON 404
curl -s -H "Accept: application/json" http://localhost:8080/invites/notexist

# Browser fetch — expect HTML
curl -s -H "Accept: text/html" http://localhost:8080/invites/notexist

# Unauthenticated create — expect 401
curl -s -X POST http://localhost:8080/invites -H "Content-Type: application/json" -d '{}'

kill %1
```

Expected: `{"error":{"code":"NOT_FOUND"...}}`, HTML with "no longer valid", `{"error":{"code":"UNAUTHORIZED"...}}`.

- [ ] **Step 3: Commit**

```bash
git add config/routes.php
git commit -m "feat: wire invite routes — public fetch outside auth group, create/list/revoke inside"
```

---

## Chunk 4: Cleanup and ship

### Task 10: Extend bin/cleanup.php

**Files:**
- Modify: `bin/cleanup.php`

- [ ] **Step 1: Add invite cleanup**

At the top of `bin/cleanup.php`, add to the existing `use` statements:

```php
use Relay\Repository\InviteRepository;
```

After the existing `$bundles` and `$storage` instantiation block, add:

```php
$inviteRepo    = new InviteRepository($pdo);
$inviteStorage = new StorageService($settings['storage']['invites_path']);
```

After step 1 (expired bundles), add:

```php
// 1b. Delete expired invites — blob first, then DB row (prevents orphaned blobs on interrupted run)
$expiredInvites = $inviteRepo->deleteExpiredBefore(date('Y-m-d H:i:s'));
foreach ($expiredInvites as $invite) {
    $inviteStorage->delete($invite['blob_path']);
}
$inviteRepo->deleteByIds(array_column($expiredInvites, 'invite_id'));
$expiredInviteCount = count($expiredInvites);
```

Update the final echo:

```php
echo "Cleanup complete: {$expiredCount} expired bundles, {$expiredInviteCount} expired invites, {$deletedAccounts} deleted accounts\n";
```

- [ ] **Step 2: Run cleanup — verify no errors**

```bash
php bin/cleanup.php
```

Expected: `Cleanup complete: 0 expired bundles, 0 expired invites, 0 deleted accounts`

- [ ] **Step 3: Commit**

```bash
git add bin/cleanup.php
git commit -m "feat: extend cleanup cron to expire invite blobs"
```

---

### Task 11: Full test suite and deploy

- [ ] **Step 1: Run entire test suite**

```bash
php vendor/bin/phpunit
```

Expected: all tests pass, 0 failures.

- [ ] **Step 2: Push to trigger deploy**

```bash
git push
```

Expected: GitHub Action runs, deploys to `swarm.krillnotes.org`.

- [ ] **Step 3: Run install on server to create storage/invites/**

After the Action completes, trigger `php bin/install.php` on the server (via cPanel terminal or a temporary web script as done previously).

- [ ] **Step 4: Smoke-test create on production**

Log in to get a session token, then:

```bash
TOKEN="<your-session-token>"

curl -s -X POST https://swarm.krillnotes.org/invites \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"payload\":\"$(echo -n 'hello' | base64)\",\"expires_at\":\"2026-12-31T00:00:00Z\"}"
```

Expected: `{"data":{"invite_id":"...","token":"...","url":"https://swarm.krillnotes.org/invites/...","expires_at":"..."}}`

- [ ] **Step 5: Smoke-test fetch on production**

```bash
INVITE_TOKEN="<token from previous step>"

# JSON fetch
curl -s -H "Accept: application/json" "https://swarm.krillnotes.org/invites/$INVITE_TOKEN"

# Browser fetch
curl -s -H "Accept: text/html" "https://swarm.krillnotes.org/invites/$INVITE_TOKEN"
```

Expected: JSON with `payload` and `expires_at`; HTML with "You've been invited to a KrillNotes workspace".
