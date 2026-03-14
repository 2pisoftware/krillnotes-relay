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

    // Handlers that need settings
    \Relay\Handler\Auth\RegisterHandler::class => function ($c) {
        return new \Relay\Handler\Auth\RegisterHandler(
            $c->get(\Relay\Repository\AccountRepository::class),
            $c->get(\Relay\Repository\DeviceKeyRepository::class),
            $c->get(\Relay\Repository\ChallengeRepository::class),
            $c->get(\Relay\Service\AuthService::class),
            $c->get(\Relay\Service\CryptoService::class),
            $c->get('settings'),
        );
    },

    \Relay\Handler\Auth\RegisterVerifyHandler::class => function ($c) {
        return new \Relay\Handler\Auth\RegisterVerifyHandler(
            $c->get(\Relay\Repository\ChallengeRepository::class),
            $c->get(\Relay\Repository\DeviceKeyRepository::class),
            $c->get(\Relay\Repository\SessionRepository::class),
            $c->get(\Relay\Service\CryptoService::class),
            $c->get('settings'),
        );
    },

    \Relay\Handler\Auth\LoginHandler::class => function ($c) {
        return new \Relay\Handler\Auth\LoginHandler(
            $c->get(\Relay\Repository\AccountRepository::class),
            $c->get(\Relay\Repository\SessionRepository::class),
            $c->get(\Relay\Service\AuthService::class),
            $c->get('settings'),
        );
    },

    \Relay\Handler\Auth\ResetPasswordHandler::class => function ($c) {
        return new \Relay\Handler\Auth\ResetPasswordHandler(
            $c->get(\Relay\Repository\AccountRepository::class),
            $c->get(\Relay\Repository\PasswordResetRepository::class),
            $c->get('settings'),
        );
    },

    \Relay\Handler\Account\AddDeviceHandler::class => function ($c) {
        return new \Relay\Handler\Account\AddDeviceHandler(
            $c->get(\Relay\Repository\DeviceKeyRepository::class),
            $c->get(\Relay\Repository\ChallengeRepository::class),
            $c->get(\Relay\Service\CryptoService::class),
            $c->get('settings'),
        );
    },

    \Relay\Handler\Bundle\UploadBundleHandler::class => function ($c) {
        return new \Relay\Handler\Bundle\UploadBundleHandler(
            $c->get(\Relay\Service\BundleRoutingService::class),
            $c->get('settings'),
        );
    },

    \Relay\Middleware\RateLimitMiddleware::class => function ($c) {
        $settings = $c->get('settings');
        return new \Relay\Middleware\RateLimitMiddleware(
            $c->get(\PDO::class),
            $settings['limits']['min_poll_interval_seconds'],
        );
    },

    // BundleRoutingService needs 4 dependencies
    \Relay\Service\BundleRoutingService::class => function ($c) {
        return new \Relay\Service\BundleRoutingService(
            $c->get(\Relay\Repository\BundleRepository::class),
            $c->get(\Relay\Repository\DeviceKeyRepository::class),
            $c->get(\Relay\Repository\AccountRepository::class),
            $c->get(\Relay\Service\StorageService::class),
        );
    },
]);

return $builder->build();
