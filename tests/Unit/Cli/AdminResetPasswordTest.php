<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);

namespace Relay\Tests\Unit\Cli;

use PDO;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Repository\AccountRepository;

final class AdminResetPasswordTest extends TestCase
{
    private PDO $pdo;
    private AccountRepository $accounts;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/bin/admin.php';

        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();
        $this->accounts = new AccountRepository($this->pdo);
    }

    public function test_reset_password_updates_hash(): void
    {
        $this->accounts->create('alice@example.com', 'oldhash', 'uuid1');

        resetPassword($this->pdo, 'alice@example.com', 'newpassword123');

        $row = $this->pdo->query(
            "SELECT password_hash FROM accounts WHERE email = 'alice@example.com'"
        )->fetch();

        $this->assertNotEquals('oldhash', $row['password_hash']);
        $this->assertTrue(password_verify('newpassword123', $row['password_hash']));
    }

    public function test_reset_password_unknown_email_exits(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No account found');

        resetPassword($this->pdo, 'nobody@example.com', 'password');
    }

    public function test_reset_password_outputs_confirmation(): void
    {
        $this->accounts->create('alice@example.com', 'oldhash', 'uuid1');

        ob_start();
        resetPassword($this->pdo, 'alice@example.com', 'newpassword123');
        $output = ob_get_clean();

        $this->assertStringContainsString('alice@example.com', $output);
        $this->assertStringContainsString('updated', $output);
    }
}
