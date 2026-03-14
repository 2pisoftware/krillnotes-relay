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
