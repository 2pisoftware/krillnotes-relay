<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);
namespace Relay\Service;
final class StorageService
{
    public function __construct(private readonly string $basePath) {}
    public function store(string $bundleId, string $data): string
    {
        $subdir = substr($bundleId, 0, 2);
        $dir = $this->basePath . '/' . $subdir;
        if (!is_dir($dir)) { mkdir($dir, 0760, true); }
        $path = $dir . '/' . $bundleId . '.swarm';
        file_put_contents($path, $data, LOCK_EX);
        return $path;
    }
    public function read(string $blobPath): ?string
    {
        if (!file_exists($blobPath)) { return null; }
        return file_get_contents($blobPath);
    }
    public function delete(string $blobPath): void
    {
        if (file_exists($blobPath)) { unlink($blobPath); }
    }
    public function size(string $blobPath): int
    {
        if (!file_exists($blobPath)) { return 0; }
        return (int) filesize($blobPath);
    }
}
