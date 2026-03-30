<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);

namespace Relay\Repository;

use PDO;

final class DeviceKeyRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function add(
        string $accountId,
        string $devicePublicKey,
        ?string $deviceId = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO device_keys (account_id, device_public_key, device_id)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$accountId, $devicePublicKey, $deviceId]);
    }

    public function markVerified(string $devicePublicKey, ?string $deviceId = null): void
    {
        if ($deviceId !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE device_keys SET verified = 1, device_id = ?
                 WHERE device_public_key = ?'
            );
            $stmt->execute([$deviceId, $devicePublicKey]);
        } else {
            $stmt = $this->pdo->prepare(
                'UPDATE device_keys SET verified = 1
                 WHERE device_public_key = ?'
            );
            $stmt->execute([$devicePublicKey]);
        }
    }

    public function findByKey(string $devicePublicKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT device_public_key, device_id, account_id, verified, added_at
             FROM device_keys WHERE device_public_key = ?'
        );
        $stmt->execute([$devicePublicKey]);
        return $stmt->fetch() ?: null;
    }

    public function findByDeviceId(string $deviceId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT device_public_key, device_id, account_id, verified, added_at
             FROM device_keys WHERE device_id = ?'
        );
        $stmt->execute([$deviceId]);
        return $stmt->fetch() ?: null;
    }

    public function findAccountByKey(string $devicePublicKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT dk.*, a.account_id, a.email
             FROM device_keys dk
             JOIN accounts a ON a.account_id = dk.account_id
             WHERE dk.device_public_key = ? AND dk.verified = 1'
        );
        $stmt->execute([$devicePublicKey]);
        return $stmt->fetch() ?: null;
    }

    public function listForAccount(string $accountId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT device_public_key, device_id, verified, added_at
             FROM device_keys WHERE account_id = ?'
        );
        $stmt->execute([$accountId]);
        return $stmt->fetchAll();
    }

    public function listVerifiedForAccount(string $accountId, ?string $excludeKey = null): array
    {
        if ($excludeKey !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT device_public_key, device_id FROM device_keys
                 WHERE account_id = ? AND verified = 1 AND device_public_key != ?'
            );
            $stmt->execute([$accountId, $excludeKey]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT device_public_key, device_id FROM device_keys
                 WHERE account_id = ? AND verified = 1'
            );
            $stmt->execute([$accountId]);
        }
        return $stmt->fetchAll();
    }

    public function remove(
        string $accountId,
        string $devicePublicKey
    ): bool {
        $stmt = $this->pdo->prepare(
            'DELETE FROM device_keys
             WHERE account_id = ? AND device_public_key = ?'
        );
        $stmt->execute([$accountId, $devicePublicKey]);
        return $stmt->rowCount() > 0;
    }
}
