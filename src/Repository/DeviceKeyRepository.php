<?php

declare(strict_types=1);

namespace Relay\Repository;

use PDO;

final class DeviceKeyRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function add(
        string $accountId,
        string $devicePublicKey
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO device_keys (account_id, device_public_key)
             VALUES (?, ?)'
        );
        $stmt->execute([$accountId, $devicePublicKey]);
    }

    public function markVerified(string $devicePublicKey): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE device_keys SET verified = 1
             WHERE device_public_key = ?'
        );
        $stmt->execute([$devicePublicKey]);
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
            'SELECT device_public_key, verified, added_at
             FROM device_keys WHERE account_id = ?'
        );
        $stmt->execute([$accountId]);
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
