<?php

declare(strict_types=1);

namespace Relay\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;

final class ConnectionTest extends TestCase
{
    private ?string $tmpFile = null;

    protected function tearDown(): void
    {
        if ($this->tmpFile !== null && file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
            // SQLite WAL mode creates -wal and -shm sidecar files
            foreach (['-wal', '-shm'] as $suffix) {
                if (file_exists($this->tmpFile . $suffix)) {
                    unlink($this->tmpFile . $suffix);
                }
            }
        }
    }

    public function test_creates_pdo_with_wal_mode(): void
    {
        // WAL journal mode is not supported on :memory: databases;
        // a real file is required.
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'relay_test_') . '.sqlite';
        $pdo = Connection::create($this->tmpFile);
        $mode = $pdo->query('PRAGMA journal_mode')->fetchColumn();
        $this->assertSame('wal', $mode);
    }

    public function test_enables_foreign_keys(): void
    {
        $pdo = Connection::create(':memory:');
        $fk = $pdo->query('PRAGMA foreign_keys')->fetchColumn();
        $this->assertSame('1', (string) $fk);
    }
}
