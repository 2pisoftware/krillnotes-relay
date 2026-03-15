<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);

namespace Relay\Repository;

use PDO;
use Ramsey\Uuid\Uuid;

final class AccountRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(
        string $email,
        string $passwordHash,
        string $identityUuid
    ): string {
        $id = Uuid::uuid4()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO accounts (account_id, email, password_hash, identity_uuid)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$id, $email, $passwordHash, $identityUuid]);
        return $id;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM accounts WHERE email = ?'
        );
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public function findById(string $accountId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM accounts WHERE account_id = ?'
        );
        $stmt->execute([$accountId]);
        return $stmt->fetch() ?: null;
    }

    public function flagForDeletion(string $accountId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE accounts
             SET flagged_for_deletion = datetime('now')
             WHERE account_id = ?"
        );
        $stmt->execute([$accountId]);
    }

    public function updatePassword(
        string $accountId,
        string $passwordHash
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE accounts SET password_hash = ? WHERE account_id = ?'
        );
        $stmt->execute([$passwordHash, $accountId]);
    }

    public function updateStorageUsed(
        string $accountId,
        int $deltaBytes
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE accounts
             SET storage_used = storage_used + ?
             WHERE account_id = ?'
        );
        $stmt->execute([$deltaBytes, $accountId]);
    }
}
