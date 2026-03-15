<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);
namespace Relay\Repository;
use PDO;
final class MailboxRepository
{
    public function __construct(private readonly PDO $pdo) {}
    public function create(string $accountId, string $workspaceId): void
    {
        $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO mailboxes (account_id, workspace_id) VALUES (?, ?)');
        $stmt->execute([$accountId, $workspaceId]);
    }
    public function delete(string $accountId, string $workspaceId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM mailboxes WHERE account_id = ? AND workspace_id = ?');
        $stmt->execute([$accountId, $workspaceId]);
        return $stmt->rowCount() > 0;
    }
    public function listForAccount(string $accountId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.workspace_id, m.registered_at,
                    COUNT(b.bundle_id) AS pending_bundles,
                    COALESCE(SUM(b.size_bytes), 0) AS storage_used
             FROM mailboxes m
             LEFT JOIN bundles b ON b.workspace_id = m.workspace_id
                AND b.recipient_device_key IN (
                    SELECT device_public_key FROM device_keys
                    WHERE account_id = ? AND verified = 1
                )
             WHERE m.account_id = ?
             GROUP BY m.workspace_id, m.registered_at'
        );
        $stmt->execute([$accountId, $accountId]);
        return $stmt->fetchAll();
    }
}
