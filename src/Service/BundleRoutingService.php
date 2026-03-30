<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);
namespace Relay\Service;
use Relay\Repository\BundleRepository;
use Relay\Repository\DeviceKeyRepository;
use Relay\Repository\AccountRepository;
final class BundleRoutingService
{
    public function __construct(
        private readonly BundleRepository $bundles,
        private readonly DeviceKeyRepository $deviceKeys,
        private readonly AccountRepository $accounts,
        private readonly StorageService $storage,
        private readonly int $maxStoragePerAccountBytes = 100 * 1024 * 1024,
    ) {}
    public function routeBundle(string $headerJson, string $payloadData): array
    {
        $header = json_decode($headerJson, true);
        if (!is_array($header) || empty($header['workspace_id']) || empty($header['sender_device_key']) || !is_array($header['recipient_device_keys'] ?? null)) {
            throw new \InvalidArgumentException('Invalid bundle header: workspace_id, sender_device_key, and recipient_device_keys[] are required');
        }
        $workspaceId = $header['workspace_id'];
        $senderKey = $header['sender_device_key'];
        $recipientKeys = $header['recipient_device_keys'];
        $recipientDeviceIds = $header['recipient_device_ids'] ?? [];
        $mode = $header['mode'] ?? 'delta';
        $validModes = ['invite', 'accept', 'snapshot', 'delta'];
        if (!in_array($mode, $validModes, true)) {
            throw new \InvalidArgumentException("Invalid bundle mode: {$mode}");
        }
        $bundleIds = [];
        $skipped = ['unverified' => [], 'unknown' => [], 'quota_exceeded' => []];
        foreach ($recipientKeys as $i => $recipientKey) {
            if ($recipientKey === $senderKey) { continue; }
            $keyRecord = $this->deviceKeys->findByKey($recipientKey);
            // Fallback: if key is unknown but we have a device_id, look up the device by ID.
            // This handles delta sync where the identity key is used for encryption
            // but routing needs the per-device relay key.
            if ($keyRecord === null && !empty($recipientDeviceIds[$i])) {
                $keyRecord = $this->deviceKeys->findByDeviceId($recipientDeviceIds[$i]);
            }
            if ($keyRecord === null) { $skipped['unknown'][] = $recipientKey; continue; }
            if (!(bool) $keyRecord['verified']) { $skipped['unverified'][] = $recipientKey; continue; }
            // Use the device's registered key for storage so ListBundles can find it.
            // When the device-ID fallback was used, $recipientKey is the identity key
            // which isn't in the device_keys table — $keyRecord has the actual registered key.
            $storageKey = $keyRecord['device_public_key'];
            $size = strlen($payloadData);
            $fullAccount = $this->accounts->findById($keyRecord['account_id']);
            $currentUsed = (int) ($fullAccount['storage_used'] ?? 0);
            if ($currentUsed + $size > $this->maxStoragePerAccountBytes) {
                $skipped['quota_exceeded'][] = $recipientKey;
                continue;
            }
            $recipientDeviceId = ($recipientDeviceIds[$i] ?? '') ?: null;
            $bundleId = \Ramsey\Uuid\Uuid::uuid4()->toString();
            $blobPath = $this->storage->store($bundleId, $payloadData);
            $this->bundles->createWithId($bundleId, $workspaceId, $senderKey, $storageKey, $mode, $size, $blobPath, $recipientDeviceId);
            $this->accounts->updateStorageUsed($keyRecord['account_id'], $size);
            $bundleIds[] = $bundleId;
        }
        return ['routed_to' => count($bundleIds), 'bundle_ids' => $bundleIds, 'skipped' => $skipped];
    }
}
