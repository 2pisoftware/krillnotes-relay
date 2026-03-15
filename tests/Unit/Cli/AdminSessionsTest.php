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
use Relay\Repository\SessionRepository;
use Relay\Repository\ChallengeRepository;

final class AdminSessionsTest extends TestCase
{
    private PDO $pdo;
    private array $settings;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/bin/admin.php';

        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();

        $this->settings = require dirname(__DIR__, 3) . '/config/settings.php';
    }

    public function test_sessions_dashboard_empty(): void
    {
        ob_start();
        showSessions($this->pdo, $this->settings, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('Sessions:', $output);
        $this->assertStringContainsString('0 active', $output);
    }

    public function test_sessions_dashboard_with_data(): void
    {
        $accountRepo = new AccountRepository($this->pdo);
        $accountId = $accountRepo->create('alice@test.com', 'hash', 'uuid');

        $sessionRepo = new SessionRepository($this->pdo);
        $sessionRepo->create($accountId, 86400 * 30);

        ob_start();
        showSessions($this->pdo, $this->settings, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('1 active', $output);
        $this->assertStringContainsString('1 accounts with active sessions', $output);
    }

    public function test_sessions_detail_shows_account(): void
    {
        $accountRepo = new AccountRepository($this->pdo);
        $accountId = $accountRepo->create('alice@test.com', 'hash', 'uuid');

        $sessionRepo = new SessionRepository($this->pdo);
        $sessionRepo->create($accountId, 86400 * 30);

        ob_start();
        showSessions($this->pdo, $this->settings, true);
        $output = ob_get_clean();

        $this->assertStringContainsString('alice@test.com', $output);
    }

    public function test_sessions_shows_challenge_summary(): void
    {
        $accountRepo = new AccountRepository($this->pdo);
        $accountId = $accountRepo->create('alice@test.com', 'hash', 'uuid');

        $challengeRepo = new ChallengeRepository($this->pdo);
        $challengeRepo->create($accountId, 'pubkey', 'nonce', 'serverpub', 'registration', 300);

        ob_start();
        showSessions($this->pdo, $this->settings, true);
        $output = ob_get_clean();

        $this->assertStringContainsString('Challenges:', $output);
        $this->assertStringContainsString('1 pending', $output);
        $this->assertStringContainsString('registration', $output);
    }
}
