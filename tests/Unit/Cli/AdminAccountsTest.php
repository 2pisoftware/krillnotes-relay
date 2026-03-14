<?php

declare(strict_types=1);

namespace Relay\Tests\Unit\Cli;

use PDO;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Repository\AccountRepository;
use Relay\Repository\DeviceKeyRepository;

final class AdminAccountsTest extends TestCase
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

    public function test_accounts_dashboard_with_no_accounts(): void
    {
        ob_start();
        showAccounts($this->pdo, $this->settings, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('Accounts:', $output);
        $this->assertStringContainsString('0 total', $output);
    }

    public function test_accounts_dashboard_with_data(): void
    {
        $repo = new AccountRepository($this->pdo);
        $repo->create('alice@example.com', 'hash', 'uuid1');
        $repo->create('bob@example.com', 'hash', 'uuid2');

        ob_start();
        showAccounts($this->pdo, $this->settings, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('2 total', $output);
    }

    public function test_accounts_detail_shows_emails(): void
    {
        $repo = new AccountRepository($this->pdo);
        $repo->create('alice@example.com', 'hash', 'uuid1');

        ob_start();
        showAccounts($this->pdo, $this->settings, true);
        $output = ob_get_clean();

        $this->assertStringContainsString('alice@example.com', $output);
    }

    public function test_accounts_detail_shows_device_counts(): void
    {
        $repo = new AccountRepository($this->pdo);
        $id = $repo->create('alice@example.com', 'hash', 'uuid1');

        $dk = new DeviceKeyRepository($this->pdo);
        $dk->add($id, 'pubkey1');
        $dk->add($id, 'pubkey2');
        $dk->markVerified('pubkey1');

        ob_start();
        showAccounts($this->pdo, $this->settings, true);
        $output = ob_get_clean();

        $this->assertStringContainsString('2 (1 verified)', $output);
    }

    public function test_accounts_detail_shows_flagged(): void
    {
        $repo = new AccountRepository($this->pdo);
        $id = $repo->create('alice@example.com', 'hash', 'uuid1');
        $repo->flagForDeletion($id);

        ob_start();
        showAccounts($this->pdo, $this->settings, true);
        $output = ob_get_clean();

        $this->assertStringContainsString('yes', $output);
    }
}
