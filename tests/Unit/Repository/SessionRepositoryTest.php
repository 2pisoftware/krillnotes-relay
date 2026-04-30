<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);

namespace Relay\Tests\Unit\Repository;

use PDO;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Repository\SessionRepository;

final class SessionRepositoryTest extends TestCase
{
    private PDO $pdo;
    private SessionRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();
        $this->repo = new SessionRepository($this->pdo);
    }

    public function test_delete_for_account_removes_all_sessions(): void
    {
        $this->seedAccount('acct-1');
        $token1 = $this->repo->create('acct-1', 3600);
        $token2 = $this->repo->create('acct-1', 3600);

        $deleted = $this->repo->deleteForAccount('acct-1');

        $this->assertSame(2, $deleted);
        $this->assertNull($this->repo->findValid($token1));
        $this->assertNull($this->repo->findValid($token2));
    }

    public function test_delete_for_account_does_not_affect_other_accounts(): void
    {
        $this->seedAccount('acct-1');
        $this->seedAccount('acct-2');
        $this->repo->create('acct-1', 3600);
        $kept = $this->repo->create('acct-2', 3600);

        $this->repo->deleteForAccount('acct-1');

        $this->assertNotNull($this->repo->findValid($kept));
    }

    public function test_delete_for_account_returns_zero_when_none_exist(): void
    {
        $this->assertSame(0, $this->repo->deleteForAccount('nonexistent'));
    }

    private function seedAccount(string $accountId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO accounts (account_id, email, password_hash, identity_uuid, role)
             VALUES (?, ?, 'hash', 'uuid', 'user')"
        );
        $stmt->execute([$accountId, $accountId . '@test.com']);
    }
}
