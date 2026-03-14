<?php
declare(strict_types=1);
namespace Relay\Repository;
use PDO;

final class InviteRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(string $inviteId, string $accountId, string $token, string $blobPath, int $sizeBytes, string $expiresAt): void
    {
        $this->pdo->prepare(
            'INSERT INTO invites (invite_id, token, account_id, blob_path, size_bytes, expires_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$inviteId, $token, $accountId, $blobPath, $sizeBytes, $expiresAt]);
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invites WHERE token = ?');
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    public function listForAccount(string $accountId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invites WHERE account_id = ? ORDER BY created_at DESC');
        $stmt->execute([$accountId]);
        return $stmt->fetchAll();
    }

    public function incrementDownloadCount(string $token): void
    {
        $this->pdo->prepare('UPDATE invites SET download_count = download_count + 1 WHERE token = ?')
            ->execute([$token]);
    }

    public function delete(string $token): ?string
    {
        $stmt = $this->pdo->prepare('SELECT blob_path FROM invites WHERE token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) { return null; }
        $this->pdo->prepare('DELETE FROM invites WHERE token = ?')->execute([$token]);
        return $row['blob_path'];
    }

    public function deleteExpiredBefore(string $now): array
    {
        // Returns expired rows only — does NOT delete. Caller deletes blobs first, then calls deleteByIds.
        $stmt = $this->pdo->prepare('SELECT invite_id, blob_path FROM invites WHERE expires_at < ?');
        $stmt->execute([$now]);
        return $stmt->fetchAll();
    }

    public function deleteByIds(array $ids): void
    {
        if (empty($ids)) { return; }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->prepare("DELETE FROM invites WHERE invite_id IN ({$placeholders})")->execute($ids);
    }
}
