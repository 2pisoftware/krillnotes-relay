<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

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
        $routing = new BundleRoutingService($bundles, $deviceKeys, $accounts, $storage, $this->pdo);
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
        $routing = new BundleRoutingService($bundles, $deviceKeys, $accounts, $storage, $this->pdo);
        $header = json_encode([
            'workspace_id' => 'ws-001',
            'sender_device_key' => 'sender_key',
            'recipient_device_keys' => ['unknown_key'],
            'mode' => 'delta',
        ]);
        $result = $routing->routeBundle($header, 'payload');
        $this->assertSame(0, $result['routed_to']);
    }

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

        $routing = new BundleRoutingService($bundles, $deviceKeys, $accounts, $storage, $this->pdo);
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

        $routing = new BundleRoutingService($bundles, $deviceKeys, $accounts, $storage, $this->pdo);
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
        $routing = new BundleRoutingService($bundles, $deviceKeys, $accounts, $storage, $this->pdo, 1);
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

        $routing = new BundleRoutingService($bundles, $deviceKeys, $accounts, $storage, $this->pdo);
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
}
