<?php

declare(strict_types=1);

namespace Relay\Database;

use PDO;

final class Connection
{
    public static function create(string $path): PDO
    {
        $pdo = new PDO("sqlite:{$path}", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec('PRAGMA foreign_keys=ON');

        return $pdo;
    }
}
