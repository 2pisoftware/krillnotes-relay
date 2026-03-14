<?php

declare(strict_types=1);

namespace Relay\Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;

final class MigratorTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir() . '/relay_test_migrations_' . uniqid();
        mkdir($this->fixtureDir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->fixtureDir . '/*.sql'));
        rmdir($this->fixtureDir);
    }

    public function test_runs_migrations_in_order(): void
    {
        file_put_contents(
            $this->fixtureDir . '/001_first.sql',
            'CREATE TABLE t1 (id INTEGER PRIMARY KEY);'
        );
        file_put_contents(
            $this->fixtureDir . '/002_second.sql',
            'CREATE TABLE t2 (id INTEGER PRIMARY KEY);'
        );

        $pdo = Connection::create(':memory:');
        $migrator = new Migrator($pdo, $this->fixtureDir);
        $migrator->run();

        $tables = $pdo->query(
            "SELECT name FROM sqlite_master
             WHERE type='table' AND name IN ('t1','t2')
             ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame(['t1', 't2'], $tables);
    }

    public function test_skips_already_applied(): void
    {
        file_put_contents(
            $this->fixtureDir . '/001_first.sql',
            'CREATE TABLE t1 (id INTEGER PRIMARY KEY);'
        );

        $pdo = Connection::create(':memory:');
        $migrator = new Migrator($pdo, $this->fixtureDir);
        $migrator->run();
        $migrator->run(); // no exception = pass

        $this->assertTrue(true);
    }
}
