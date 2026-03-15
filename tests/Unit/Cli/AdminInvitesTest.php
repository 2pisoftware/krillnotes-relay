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
use Relay\Repository\InviteRepository;

final class AdminInvitesTest extends TestCase
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

    public function test_invites_dashboard_empty(): void
    {
        ob_start();
        showInvites($this->pdo, $this->settings, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('Invites:', $output);
        $this->assertStringContainsString('0 active', $output);
    }

    public function test_invites_dashboard_with_data(): void
    {
        $this->seedInvite('alice@test.com', 2048, 5);

        ob_start();
        showInvites($this->pdo, $this->settings, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('1 active', $output);
        $this->assertStringContainsString('5 total downloads', $output);
    }

    public function test_invites_detail_shows_token_and_account(): void
    {
        $this->seedInvite('alice@test.com', 1024, 0);

        ob_start();
        showInvites($this->pdo, $this->settings, true);
        $output = ob_get_clean();

        $this->assertStringContainsString('alice@test.com', $output);
        // Token should be truncated
        $this->assertStringContainsString('...', $output);
    }

    private function seedInvite(string $email, int $size, int $downloads): void
    {
        $accountRepo = new AccountRepository($this->pdo);
        $accountId = $accountRepo->create($email, 'hash', 'uuid-' . bin2hex(random_bytes(4)));

        $inviteRepo = new InviteRepository($this->pdo);
        $inviteId = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
        $inviteRepo->create($inviteId, $accountId, $token, "invites/ab/{$inviteId}.swarm", $size, $expiresAt);

        // Simulate downloads
        for ($i = 0; $i < $downloads; $i++) {
            $inviteRepo->incrementDownloadCount($token);
        }
    }
}
