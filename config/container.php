<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

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

    'InviteStorageService' => function ($c) {
        return new StorageService($c->get('settings')['storage']['invites_path']);
    },

    \Relay\Handler\Invite\CreateInviteHandler::class => function ($c) {
        return new \Relay\Handler\Invite\CreateInviteHandler(
            $c->get(\Relay\Repository\InviteRepository::class),
            $c->get('InviteStorageService'),
            $c->get('settings'),
        );
    },

    \Relay\Handler\Invite\ListInvitesHandler::class => function ($c) {
        return new \Relay\Handler\Invite\ListInvitesHandler(
            $c->get(\Relay\Repository\InviteRepository::class),
            $c->get('settings'),
        );
    },

    \Relay\Handler\Invite\RevokeInviteHandler::class => function ($c) {
        return new \Relay\Handler\Invite\RevokeInviteHandler(
            $c->get(\Relay\Repository\InviteRepository::class),
            $c->get('InviteStorageService'),
        );
    },

    \Relay\Handler\Invite\FetchInviteHandler::class => function ($c) {
        return new \Relay\Handler\Invite\FetchInviteHandler(
            $c->get(\Relay\Repository\InviteRepository::class),
            $c->get('InviteStorageService'),
            $c->get('settings'),
        );
    },

    // Handlers that need settings
    \Relay\Handler\Auth\RegisterHandler::class => function ($c) {
        return new \Relay\Handler\Auth\RegisterHandler(
            $c->get(\Relay\Repository\AccountRepository::class),
            $c->get(\Relay\Repository\DeviceKeyRepository::class),
            $c->get(\Relay\Repository\ChallengeRepository::class),
            $c->get(\Relay\Service\AuthService::class),
            $c->get(\Relay\Service\CryptoService::class),
            $c->get(\PDO::class),
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
            $c->get(\Relay\Repository\DeviceKeyRepository::class),
            $c->get(\Relay\Repository\ChallengeRepository::class),
            $c->get(\Relay\Service\CryptoService::class),
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

    \Relay\Service\BundleRoutingService::class => function ($c) {
        $settings = $c->get('settings');
        return new \Relay\Service\BundleRoutingService(
            $c->get(\Relay\Repository\BundleRepository::class),
            $c->get(\Relay\Repository\DeviceKeyRepository::class),
            $c->get(\Relay\Repository\AccountRepository::class),
            $c->get(\Relay\Service\StorageService::class),
            $c->get(\PDO::class),
            $settings['limits']['max_storage_per_account_bytes'],
        );
    },
]);

return $builder->build();
