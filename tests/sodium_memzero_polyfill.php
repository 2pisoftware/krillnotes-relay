<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);

/**
 * No-op polyfill for sodium_memzero when ext-sodium is absent.
 *
 * sodium_compat cannot implement sodium_memzero (it requires OS-level memory
 * wiping) and throws SodiumException if called. In the test environment this
 * is acceptable: we zero the string bytes as a best-effort measure.
 * Production deployments have the real ext-sodium with proper wiping.
 *
 * This file must be loaded BEFORE paragonie/sodium_compat so that
 * sodium_compat's !is_callable() guard skips its throwing implementation.
 */
if (!extension_loaded('sodium') && !function_exists('sodium_memzero')) {
    function sodium_memzero(string &$secret): void
    {
        $secret = str_repeat("\0", strlen($secret));
    }
}
