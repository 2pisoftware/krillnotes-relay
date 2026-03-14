<?php

declare(strict_types=1);

namespace Relay\Tests\Unit\Cli;

use PDO;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;

final class AdminHealthTest extends TestCase
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

    public function test_health_dashboard(): void
    {
        ob_start();
        showHealth($this->pdo, $this->settings, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('Health:', $output);
        $this->assertStringContainsString('migrations applied', $output);
    }

    public function test_health_detail_shows_settings(): void
    {
        ob_start();
        showHealth($this->pdo, $this->settings, true);
        $output = ob_get_clean();

        $this->assertStringContainsString('=== SETTINGS ===', $output);
        $this->assertStringContainsString('Bundle retention:', $output);
        $this->assertStringContainsString('30 days', $output);
        $this->assertStringContainsString('Max storage/account:', $output);
        $this->assertStringContainsString('100.0 MB', $output);
    }

    public function test_health_detail_shows_migrations(): void
    {
        ob_start();
        showHealth($this->pdo, $this->settings, true);
        $output = ob_get_clean();

        $this->assertStringContainsString('=== DATABASE ===', $output);
        $this->assertStringContainsString('Migrations:', $output);
        // All 8 migrations should be applied
        $this->assertStringContainsString('8/8 applied', $output);
    }
}
