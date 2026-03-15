<?php

declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Relay\Middleware\AuthMiddleware;
use Relay\Middleware\RateLimitMiddleware;

return function (App $app) {
    // Welcome page (browser visitors)
    $app->get('/', \Relay\Handler\WelcomeHandler::class);

    // Public auth endpoints
    $app->post('/auth/register',
        \Relay\Handler\Auth\RegisterHandler::class);
    $app->post('/auth/register/verify',
        \Relay\Handler\Auth\RegisterVerifyHandler::class);
    $app->post('/auth/login',
        \Relay\Handler\Auth\LoginHandler::class);
    $app->post('/auth/reset-password',
        \Relay\Handler\Auth\ResetPasswordHandler::class);
    $app->post('/auth/reset-password/confirm',
        \Relay\Handler\Auth\ResetPasswordConfirmHandler::class);

    // Public invite fetch
    $app->get('/invites/{token}', \Relay\Handler\Invite\FetchInviteHandler::class);

    // Authenticated endpoints
    $app->group('', function (RouteCollectorProxy $group) {
        $group->post('/auth/logout',
            \Relay\Handler\Auth\LogoutHandler::class);

        $group->get('/account',
            \Relay\Handler\Account\GetAccountHandler::class);
        $group->delete('/account',
            \Relay\Handler\Account\DeleteAccountHandler::class);
        $group->post('/account/devices',
            \Relay\Handler\Account\AddDeviceHandler::class);
        $group->post('/account/devices/verify',
            \Relay\Handler\Account\VerifyDeviceHandler::class);
        $group->delete('/account/devices/{device_key}',
            \Relay\Handler\Account\RemoveDeviceHandler::class);

        $group->post('/mailboxes',
            \Relay\Handler\Mailbox\CreateMailboxHandler::class);
        $group->delete('/mailboxes/{workspace_id}',
            \Relay\Handler\Mailbox\DeleteMailboxHandler::class);
        $group->get('/mailboxes',
            \Relay\Handler\Mailbox\ListMailboxesHandler::class);

        $group->post('/bundles',
            \Relay\Handler\Bundle\UploadBundleHandler::class);
        $group->get('/bundles',
            \Relay\Handler\Bundle\ListBundlesHandler::class)
            ->add(RateLimitMiddleware::class);
        $group->get('/bundles/{bundle_id}',
            \Relay\Handler\Bundle\DownloadBundleHandler::class);
        $group->delete('/bundles/{bundle_id}',
            \Relay\Handler\Bundle\DeleteBundleHandler::class);

        $group->post('/invites',           \Relay\Handler\Invite\CreateInviteHandler::class);
        $group->get('/invites',            \Relay\Handler\Invite\ListInvitesHandler::class);
        $group->delete('/invites/{token}', \Relay\Handler\Invite\RevokeInviteHandler::class);
    })->add(AuthMiddleware::class);
};
