<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);

define('PHPUNIT_RUNNING', true);

require __DIR__ . '/../vendor/autoload.php';

// When running from a worktree, the vendor symlink resolves to the main repo.
// Re-register the worktree's own src/ and tests/ directories with higher priority
// so that modified classes in the worktree take precedence.
$worktreeRoot = dirname(__DIR__);
$mainVendorRoot = realpath(__DIR__ . '/../vendor');
$mainRepoRoot = dirname($mainVendorRoot);

if ($worktreeRoot !== $mainRepoRoot) {
    spl_autoload_register(function (string $class) use ($worktreeRoot): void {
        if (str_starts_with($class, 'Relay\\Tests\\')) {
            $relative = substr($class, strlen('Relay\\Tests\\'));
            $path = $worktreeRoot . '/tests/' . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($path)) {
                require_once $path;
            }
        } elseif (str_starts_with($class, 'Relay\\')) {
            $relative = substr($class, strlen('Relay\\'));
            $path = $worktreeRoot . '/src/' . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }, prepend: true);
}
