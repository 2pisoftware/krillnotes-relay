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
use Relay\Repository\AccountRepository;

final class AccountRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AccountRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();
        $this->repo = new AccountRepository($this->pdo);
    }

    public function test_create_and_find_by_email(): void
    {
        $id = $this->repo->create(
            'test@example.com',
            'hashed_pw',
            'identity-uuid-1'
        );

        $account = $this->repo->findByEmail('test@example.com');
        $this->assertNotNull($account);
        $this->assertSame($id, $account['account_id']);
        $this->assertSame('user', $account['role']);
        $this->assertSame(0, (int) $account['storage_used']);
    }

    public function test_find_by_id(): void
    {
        $id = $this->repo->create('a@b.com', 'hash', 'uuid');
        $account = $this->repo->findById($id);

        $this->assertNotNull($account);
        $this->assertSame('a@b.com', $account['email']);
    }

    public function test_flag_for_deletion(): void
    {
        $id = $this->repo->create('a@b.com', 'hash', 'uuid');
        $this->repo->flagForDeletion($id);

        $account = $this->repo->findById($id);
        $this->assertNotNull($account['flagged_for_deletion']);
    }

    public function test_duplicate_email_throws(): void
    {
        $this->repo->create('a@b.com', 'hash', 'uuid1');
        $this->expectException(\PDOException::class);
        $this->repo->create('a@b.com', 'hash', 'uuid2');
    }
}
