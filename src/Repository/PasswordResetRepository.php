<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);
namespace Relay\Repository;
use PDO;
final class PasswordResetRepository
{
    public function __construct(private readonly PDO $pdo) {}
    public function create(string $accountId, int $lifetimeSeconds): string
    {
        $token = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare("INSERT INTO password_resets (token, account_id, expires_at) VALUES (?, ?, datetime('now', '+' || ? || ' seconds'))");
        $stmt->execute([$token, $accountId, $lifetimeSeconds]);
        return $token;
    }
    public function findValid(string $token): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM password_resets WHERE token = ? AND used = 0 AND expires_at > datetime('now')");
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }
    public function markUsed(string $token): void
    {
        $stmt = $this->pdo->prepare('UPDATE password_resets SET used = 1 WHERE token = ?');
        $stmt->execute([$token]);
    }
}
