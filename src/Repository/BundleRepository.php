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
final class BundleRepository
{
    public function __construct(private readonly PDO $pdo) {}
    public function createWithId(string $bundleId, string $workspaceId, string $senderDeviceKey, string $recipientDeviceKey, string $mode, int $sizeBytes, string $blobPath): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO bundles (bundle_id, workspace_id, sender_device_key, recipient_device_key, mode, size_bytes, blob_path) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$bundleId, $workspaceId, $senderDeviceKey, $recipientDeviceKey, $mode, $sizeBytes, $blobPath]);
    }
    public function findById(string $bundleId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM bundles WHERE bundle_id = ?');
        $stmt->execute([$bundleId]);
        return $stmt->fetch() ?: null;
    }
    public function listForRecipientKeys(array $deviceKeys): array
    {
        if (empty($deviceKeys)) { return []; }
        $placeholders = implode(',', array_fill(0, count($deviceKeys), '?'));
        $stmt = $this->pdo->prepare("SELECT bundle_id, workspace_id, sender_device_key, mode, size_bytes, created_at FROM bundles WHERE recipient_device_key IN ({$placeholders}) ORDER BY created_at ASC");
        $stmt->execute($deviceKeys);
        return $stmt->fetchAll();
    }
    public function delete(string $bundleId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT blob_path, size_bytes, recipient_device_key FROM bundles WHERE bundle_id = ?');
        $stmt->execute([$bundleId]);
        $bundle = $stmt->fetch();
        if (!$bundle) { return null; }
        $this->pdo->prepare('DELETE FROM bundles WHERE bundle_id = ?')->execute([$bundleId]);
        return $bundle['blob_path'];
    }
    public function deleteExpiredBefore(string $datetime): array
    {
        $stmt = $this->pdo->prepare('SELECT bundle_id, blob_path FROM bundles WHERE created_at < ?');
        $stmt->execute([$datetime]);
        $expired = $stmt->fetchAll();
        if (!empty($expired)) {
            $ids = array_column($expired, 'bundle_id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $this->pdo->prepare("DELETE FROM bundles WHERE bundle_id IN ({$placeholders})")->execute($ids);
        }
        return $expired;
    }
}
