<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);
namespace Relay\Tests\Integration\Device;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Handler\Account\ListDevicesHandler;
use Relay\Repository\AccountRepository;
use Relay\Repository\BundleRepository;
use Relay\Repository\DeviceKeyRepository;
use Relay\Service\BundleRoutingService;
use Relay\Service\StorageService;
use Slim\Psr7\Factory\ServerRequestFactory;
final class DeviceIdTest extends TestCase
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
        $this->storagePath = sys_get_temp_dir() . '/relay_device_id_test_' . uniqid();
        mkdir($this->storagePath, 0777, true);
        $this->accounts = new AccountRepository($this->pdo);
        $this->bundles = new BundleRepository($this->pdo);
        $this->deviceKeys = new DeviceKeyRepository($this->pdo);
        $this->storage = new StorageService($this->storagePath);
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

    public function test_device_id_stored_in_bundle_when_provided_in_header(): void
    {
        $senderId = $this->accounts->create('sender@test.com', 'hash', 'uuid-s');
        $this->deviceKeys->add($senderId, 'sender_key_hex');
        $this->deviceKeys->markVerified('sender_key_hex');

        $recipientId = $this->accounts->create('recipient@test.com', 'hash', 'uuid-r');
        $this->deviceKeys->add($recipientId, 'recipient_key_hex');
        $this->deviceKeys->markVerified('recipient_key_hex');

        $routing = new BundleRoutingService($this->bundles, $this->deviceKeys, $this->accounts, $this->storage);
        $header = json_encode([
            'workspace_id' => 'ws-001',
            'sender_device_key' => 'sender_key_hex',
            'recipient_device_keys' => ['recipient_key_hex'],
            'recipient_device_ids' => ['device-id-abc123'],
            'mode' => 'delta',
        ]);

        $result = $routing->routeBundle($header, 'payload');
        $this->assertSame(1, $result['routed_to']);

        // Verify device_id was stored
        $bundleId = $result['bundle_ids'][0];
        $bundle = $this->bundles->findById($bundleId);
        $this->assertSame('device-id-abc123', $bundle['recipient_device_id']);
    }

    public function test_device_id_null_when_not_provided_in_header(): void
    {
        $senderId = $this->accounts->create('sender@test.com', 'hash', 'uuid-s');
        $this->deviceKeys->add($senderId, 'sender_key_hex');
        $this->deviceKeys->markVerified('sender_key_hex');

        $recipientId = $this->accounts->create('recipient@test.com', 'hash', 'uuid-r');
        $this->deviceKeys->add($recipientId, 'recipient_key_hex');
        $this->deviceKeys->markVerified('recipient_key_hex');

        $routing = new BundleRoutingService($this->bundles, $this->deviceKeys, $this->accounts, $this->storage);
        // Old-style header without recipient_device_ids
        $header = json_encode([
            'workspace_id' => 'ws-001',
            'sender_device_key' => 'sender_key_hex',
            'recipient_device_keys' => ['recipient_key_hex'],
            'mode' => 'delta',
        ]);

        $result = $routing->routeBundle($header, 'payload');
        $this->assertSame(1, $result['routed_to']);

        $bundleId = $result['bundle_ids'][0];
        $bundle = $this->bundles->findById($bundleId);
        $this->assertNull($bundle['recipient_device_id']);
    }

    public function test_list_for_recipient_keys_filters_by_device_id(): void
    {
        $senderId = $this->accounts->create('sender@test.com', 'hash', 'uuid-s');
        $this->deviceKeys->add($senderId, 'sender_key_hex');
        $this->deviceKeys->markVerified('sender_key_hex');

        $recipientId = $this->accounts->create('recipient@test.com', 'hash', 'uuid-r');
        $this->deviceKeys->add($recipientId, 'recipient_key_hex');
        $this->deviceKeys->markVerified('recipient_key_hex');

        $routing = new BundleRoutingService($this->bundles, $this->deviceKeys, $this->accounts, $this->storage);

        // Bundle for device-A
        $routing->routeBundle(json_encode([
            'workspace_id' => 'ws-001',
            'sender_device_key' => 'sender_key_hex',
            'recipient_device_keys' => ['recipient_key_hex'],
            'recipient_device_ids' => ['device-A'],
            'mode' => 'delta',
        ]), 'payload-for-A');

        // Bundle for device-B
        $routing->routeBundle(json_encode([
            'workspace_id' => 'ws-001',
            'sender_device_key' => 'sender_key_hex',
            'recipient_device_keys' => ['recipient_key_hex'],
            'recipient_device_ids' => ['device-B'],
            'mode' => 'delta',
        ]), 'payload-for-B');

        // Filter by device-A: should return only 1 bundle
        $forA = $this->bundles->listForRecipientKeys(['recipient_key_hex'], 'device-A');
        $this->assertCount(1, $forA);

        // Filter by device-B: should return only 1 bundle
        $forB = $this->bundles->listForRecipientKeys(['recipient_key_hex'], 'device-B');
        $this->assertCount(1, $forB);

        // No filter: should return both
        $all = $this->bundles->listForRecipientKeys(['recipient_key_hex']);
        $this->assertCount(2, $all);
    }

    public function test_list_devices_handler_returns_verified_peers(): void
    {
        $accountId = $this->accounts->create('user@test.com', 'hash', 'uuid-u');
        $this->deviceKeys->add($accountId, 'my_key_hex', 'my-device-id');
        $this->deviceKeys->markVerified('my_key_hex');

        $this->deviceKeys->add($accountId, 'peer_key_hex', 'peer-device-id');
        $this->deviceKeys->markVerified('peer_key_hex');

        // Unverified device — should NOT appear
        $this->deviceKeys->add($accountId, 'unverified_key_hex', 'unverified-device-id');

        $handler = new ListDevicesHandler($this->deviceKeys);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/account/devices')
            ->withAttribute('account_id', $accountId)
            ->withQueryParams(['exclude_key' => 'my_key_hex']);

        $response = $handler($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true)['data'];

        // Should contain only peer (my own key excluded, unverified excluded)
        $this->assertCount(1, $data);
        $this->assertSame('peer_key_hex', $data[0]['device_key']);
        $this->assertSame('peer-device-id', $data[0]['device_id']);
    }

    public function test_list_devices_handler_returns_all_verified_when_no_exclude(): void
    {
        $accountId = $this->accounts->create('user@test.com', 'hash', 'uuid-u');
        $this->deviceKeys->add($accountId, 'key_one', 'device-one');
        $this->deviceKeys->markVerified('key_one');

        $this->deviceKeys->add($accountId, 'key_two', 'device-two');
        $this->deviceKeys->markVerified('key_two');

        $handler = new ListDevicesHandler($this->deviceKeys);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/account/devices')
            ->withAttribute('account_id', $accountId);

        $response = $handler($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true)['data'];
        $this->assertCount(2, $data);
    }

    public function test_device_id_stored_via_add_method(): void
    {
        $accountId = $this->accounts->create('user@test.com', 'hash', 'uuid-u');
        $this->deviceKeys->add($accountId, 'test_key_hex', 'composite-device-id-xyz');
        $this->deviceKeys->markVerified('test_key_hex');

        $devices = $this->deviceKeys->listVerifiedForAccount($accountId);
        $this->assertCount(1, $devices);
        $this->assertSame('composite-device-id-xyz', $devices[0]['device_id']);
    }

    public function test_mark_verified_updates_device_id_when_provided(): void
    {
        $accountId = $this->accounts->create('user@test.com', 'hash', 'uuid-u');
        // Add without device_id
        $this->deviceKeys->add($accountId, 'test_key_hex');
        // Verify with device_id
        $this->deviceKeys->markVerified('test_key_hex', 'late-device-id');

        $devices = $this->deviceKeys->listVerifiedForAccount($accountId);
        $this->assertCount(1, $devices);
        $this->assertSame('late-device-id', $devices[0]['device_id']);
    }
}
