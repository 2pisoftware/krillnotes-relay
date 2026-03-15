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
