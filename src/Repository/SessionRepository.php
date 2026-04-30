<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);

namespace Relay\Repository;

use PDO;

final class SessionRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(
        string $accountId,
        int $lifetimeSeconds
    ): string {
        $token = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare(
            "INSERT INTO sessions (token, account_id, expires_at)
             VALUES (?, ?, datetime('now', '+' || ? || ' seconds'))"
        );
        $stmt->execute([$token, $accountId, $lifetimeSeconds]);
        return $token;
    }

    public function findValid(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM sessions
             WHERE token = ? AND expires_at > datetime('now')"
        );
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    public function delete(string $token): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE token = ?');
        $stmt->execute([$token]);
    }

    public function deleteForAccount(string $accountId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE account_id = ?');
        $stmt->execute([$accountId]);
        return $stmt->rowCount();
    }

    public function deleteExpired(): int
    {
        return $this->pdo->exec(
            "DELETE FROM sessions WHERE expires_at <= datetime('now')"
        );
    }
}
