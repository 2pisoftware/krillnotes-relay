<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);

namespace Relay\Database;

use PDO;

final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsPath,
    ) {}

    public function run(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS _migrations (
                filename TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL DEFAULT (datetime('now'))
            )"
        );

        $applied = $this->pdo->query('SELECT filename FROM _migrations')
            ->fetchAll(PDO::FETCH_COLUMN);

        $files = glob($this->migrationsPath . '/*.sql');
        sort($files);

        foreach ($files as $file) {
            $filename = basename($file);
            if (in_array($filename, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file);
            $this->pdo->exec($sql);

            $stmt = $this->pdo->prepare(
                'INSERT INTO _migrations (filename) VALUES (?)'
            );
            $stmt->execute([$filename]);
        }
    }
}
