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
