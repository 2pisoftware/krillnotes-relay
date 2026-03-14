<?php
declare(strict_types=1);
namespace Relay\Service;
final class StorageService
{
    public function __construct(private readonly string $basePath) {}
    public function store(string $bundleId, string $data): string
    {
        $subdir = substr($bundleId, 0, 2);
        $dir = $this->basePath . '/' . $subdir;
        if (!is_dir($dir)) { mkdir($dir, 0750, true); }
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
