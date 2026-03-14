<?php

declare(strict_types=1);

namespace Relay\Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;

final class SchemaTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Connection::create(':memory:');
        $migrator = new Migrator(
            $this->pdo,
            dirname(__DIR__, 3) . '/migrations'
        );
        $migrator->run();
    }

    public function test_all_tables_created(): void
    {
        $tables = $this->pdo->query(
            "SELECT name FROM sqlite_master
             WHERE type='table'
               AND name NOT LIKE '\_%' ESCAPE '\'
               AND name NOT LIKE 'sqlite\_%' ESCAPE '\'
             ORDER BY name"
        )->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertSame([
            'accounts',
            'bundles',
            'challenges',
            'device_keys',
            'invites',
            'mailboxes',
            'password_resets',
            'sessions',
        ], $tables);
    }

    public function test_device_key_unique_constraint(): void
    {
        $this->pdo->exec(
            "INSERT INTO accounts
                (account_id, email, password_hash, identity_uuid)
             VALUES
                ('a1', 'a@b.com', 'hash', 'uuid1')"
        );
        $this->pdo->exec(
            "INSERT INTO device_keys
                (account_id, device_public_key, verified)
             VALUES ('a1', 'key1', 1)"
        );

        $this->expectException(\PDOException::class);
        $this->pdo->exec(
            "INSERT INTO device_keys
                (account_id, device_public_key, verified)
             VALUES ('a1', 'key1', 1)"
        );
    }
}
