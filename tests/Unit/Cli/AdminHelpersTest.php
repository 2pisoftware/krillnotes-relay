<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);

namespace Relay\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;

// We'll require the admin.php file and test its functions directly.
// The functions will be defined in the global namespace inside bin/admin.php.

final class AdminHelpersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Load the admin functions (the file guards against double-include
        // and skips dispatch when included from tests).
        require_once dirname(__DIR__, 3) . '/bin/admin.php';
    }

    // --- humanBytes ---

    public function test_human_bytes_zero(): void
    {
        $this->assertSame('0 B', humanBytes(0));
    }

    public function test_human_bytes_bytes(): void
    {
        $this->assertSame('512 B', humanBytes(512));
    }

    public function test_human_bytes_kilobytes(): void
    {
        $this->assertSame('1.5 KB', humanBytes(1536));
    }

    public function test_human_bytes_megabytes(): void
    {
        $this->assertSame('10.0 MB', humanBytes(10 * 1024 * 1024));
    }

    public function test_human_bytes_gigabytes(): void
    {
        $this->assertSame('2.3 GB', humanBytes(2469606195));
    }

    // --- relativeTime ---

    public function test_relative_time_null(): void
    {
        $this->assertSame('never', relativeTime(null));
    }

    public function test_relative_time_seconds_ago(): void
    {
        $ts = date('Y-m-d H:i:s', strtotime('-30 seconds'));
        $this->assertSame('just now', relativeTime($ts));
    }

    public function test_relative_time_minutes_ago(): void
    {
        $ts = date('Y-m-d H:i:s', strtotime('-5 minutes'));
        $this->assertSame('5m ago', relativeTime($ts));
    }

    public function test_relative_time_hours_ago(): void
    {
        $ts = date('Y-m-d H:i:s', strtotime('-3 hours'));
        $this->assertSame('3h ago', relativeTime($ts));
    }

    public function test_relative_time_days_ago(): void
    {
        $ts = date('Y-m-d H:i:s', strtotime('-12 days'));
        $this->assertSame('12d ago', relativeTime($ts));
    }

    // --- relativeTimeFuture ---

    public function test_relative_time_future_minutes(): void
    {
        $ts = date('Y-m-d H:i:s', strtotime('+48 minutes'));
        $this->assertSame('in 48m', relativeTimeFuture($ts));
    }

    public function test_relative_time_future_hours(): void
    {
        $ts = date('Y-m-d H:i:s', strtotime('+5 hours'));
        $this->assertSame('in 5h', relativeTimeFuture($ts));
    }

    public function test_relative_time_future_days(): void
    {
        $ts = date('Y-m-d H:i:s', strtotime('+16 days'));
        $this->assertSame('in 16d', relativeTimeFuture($ts));
    }

    public function test_relative_time_future_already_expired(): void
    {
        $ts = date('Y-m-d H:i:s', strtotime('-2 days'));
        $this->assertSame('expired 2d ago!', relativeTimeFuture($ts));
    }

    // --- progressBar ---

    public function test_progress_bar_normal(): void
    {
        $result = progressBar(54 * 1024 * 1024, 100 * 1024 * 1024);
        $this->assertSame('54.0 MB / 100.0 MB (54%)', $result);
    }

    public function test_progress_bar_zero(): void
    {
        $result = progressBar(0, 100 * 1024 * 1024);
        $this->assertSame('0 B / 100.0 MB (0%)', $result);
    }

    public function test_progress_bar_overflow(): void
    {
        $current = 112 * 1024 * 1024;
        $max = 100 * 1024 * 1024;
        $result = progressBar($current, $max);
        $this->assertSame('112.0 MB / 100.0 MB (112%)', $result);
    }
}
