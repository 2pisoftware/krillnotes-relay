<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);

namespace Relay\Repository;

use PDO;

final class ChallengeRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(
        ?string $accountId,
        string $devicePublicKey,
        string $nonceHex,
        string $serverPublicKeyHex,
        string $context,
        int $lifetimeSeconds
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO challenges
                (account_id, device_public_key, nonce, server_public_key,
                 context, expires_at)
             VALUES (?, ?, ?, ?, ?, datetime('now', '+' || ? || ' seconds'))"
        );
        $stmt->execute([
            $accountId,
            $devicePublicKey,
            $nonceHex,
            $serverPublicKeyHex,
            $context,
            $lifetimeSeconds,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findValid(
        string $devicePublicKey,
        string $context
    ): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM challenges
             WHERE device_public_key = ?
               AND context = ?
               AND expires_at > datetime('now')
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$devicePublicKey, $context]);
        return $stmt->fetch() ?: null;
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM challenges WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function deleteExpired(): int
    {
        $deleted = $this->pdo->exec(
            "DELETE FROM challenges WHERE expires_at <= datetime('now')"
        );
        return $deleted;
    }
}
