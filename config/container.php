<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Relay\Database\Connection;
use Relay\Service\StorageService;

$builder = new ContainerBuilder();

$builder->addDefinitions([
    'settings' => require __DIR__ . '/settings.php',

    PDO::class => function ($c) {
        $settings = $c->get('settings');
        return Connection::create($settings['database']['path']);
    },

    StorageService::class => function ($c) {
        $settings = $c->get('settings');
        return new StorageService($settings['storage']['bundles_path']);
    },
]);

return $builder->build();
