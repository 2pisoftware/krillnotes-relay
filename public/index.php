<?php

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
