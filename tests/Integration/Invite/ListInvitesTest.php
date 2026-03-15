<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

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
