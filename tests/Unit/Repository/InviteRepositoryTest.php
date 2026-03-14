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
