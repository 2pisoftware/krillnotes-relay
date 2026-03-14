<?php
declare(strict_types=1);
namespace Relay\Tests\Integration\Bundle;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Repository\AccountRepository;
use Relay\Repository\BundleRepository;
use Relay\Repository\DeviceKeyRepository;
use Relay\Service\BundleRoutingService;
use Relay\Service\StorageService;
final class UploadRouteTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;
    protected function setUp(): void
    {
        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();
        $this->storagePath = sys_get_temp_dir() . '/relay_test_bundles_' . uniqid();
        mkdir($this->storagePath, 0777, true);
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
    public function test_bundle_routed_to_registered_recipient(): void
    {
        $accounts = new AccountRepository($this->pdo);
        $deviceKeys = new DeviceKeyRepository($this->pdo);
        $bundles = new BundleRepository($this->pdo);
        $storage = new StorageService($this->storagePath);
        $senderId = $accounts->create('sender@test.com', 'hash', 'uuid-s');
        $deviceKeys->add($senderId, 'sender_key_hex');
        $deviceKeys->markVerified('sender_key_hex');
        $recipientId = $accounts->create('recipient@test.com', 'hash', 'uuid-r');
        $deviceKeys->add($recipientId, 'recipient_key_hex');
        $deviceKeys->markVerified('recipient_key_hex');
        $routing = new BundleRoutingService($bundles, $deviceKeys, $accounts, $storage);
        $header = json_encode([
            'workspace_id' => 'ws-001',
            'sender_device_key' => 'sender_key_hex',
            'recipient_device_keys' => ['recipient_key_hex', 'sender_key_hex'],
            'mode' => 'delta',
        ]);
        $result = $routing->routeBundle($header, 'opaque-encrypted-payload');
        $this->assertSame(1, $result['routed_to']);
        $this->assertCount(1, $result['bundle_ids']);
        $pending = $bundles->listForRecipientKeys(['recipient_key_hex']);
        $this->assertCount(1, $pending);
        $this->assertSame('ws-001', $pending[0]['workspace_id']);
    }
    public function test_bundle_not_routed_to_unknown_key(): void
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
            'recipient_device_keys' => ['unknown_key'],
            'mode' => 'delta',
        ]);
        $result = $routing->routeBundle($header, 'payload');
        $this->assertSame(0, $result['routed_to']);
    }
}
