<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use DI\Bridge\Slim\Bridge;
use Relay\Middleware\JsonBodyMiddleware;

$container = require __DIR__ . '/../config/container.php';
$app = Bridge::create($container);

$app->addErrorMiddleware(false, true, true);
$app->add(new JsonBodyMiddleware());

$routes = require __DIR__ . '/../config/routes.php';
$routes($app);

$app->run();
