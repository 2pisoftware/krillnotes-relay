<?php
declare(strict_types=1);
namespace Relay\Handler\Invite;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Relay\Repository\InviteRepository;
use Relay\Service\StorageService;
use Slim\Psr7\Response;

final class FetchInviteHandler
{
    public function __construct(
        private readonly InviteRepository $invites,
        private readonly StorageService $storage,
        private readonly array $settings,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $token     = $request->getAttribute('token');
        $invite    = $this->invites->findByToken($token);
        $wantsJson = str_contains($request->getHeaderLine('Accept'), 'application/json');

        if ($invite === null) {
            return $wantsJson
                ? $this->json(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'Invite not found']])
                : $this->html(404, $this->expiredHtml());
        }

        if (strtotime($invite['expires_at']) <= time()) {
            return $wantsJson
                ? $this->json(410, ['error' => ['code' => 'GONE', 'message' => 'This invite has expired']])
                : $this->html(410, $this->expiredHtml());
        }

        if ($wantsJson) {
            $blob = $this->storage->read($invite['blob_path']);
            if ($blob === null) {
                return $this->json(410, ['error' => ['code' => 'GONE', 'message' => 'Invite payload not found']]);
            }
            $this->invites->incrementDownloadCount($token);
            $response = new Response(200);
            $response->getBody()->write(json_encode(['data' => [
                'payload'    => base64_encode($blob),
                'expires_at' => $invite['expires_at'],
            ]]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $baseUrl = rtrim($this->settings['base_url'], '/');
        $url     = htmlspecialchars("{$baseUrl}/invites/{$token}");
        return $this->html(200, <<<HTML
            <!doctype html><html lang="en"><head><meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>KrillNotes Invite</title></head><body>
            <h1>You've been invited to a KrillNotes workspace</h1>
            <p>To accept this invite, open it in the KrillNotes app.</p>
            <p>If you don't have KrillNotes yet, download it at
            <a href="https://krillnotes.com">krillnotes.com</a>.</p>
            <p>Your invite URL:<br><code>{$url}</code></p>
            </body></html>
            HTML);
    }

    private function expiredHtml(): string
    {
        return <<<HTML
            <!doctype html><html lang="en"><head><meta charset="utf-8">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title>Invite No Longer Valid</title></head><body>
            <h1>This invite is no longer valid</h1>
            <p>The invite link has expired or been revoked.</p>
            </body></html>
            HTML;
    }

    private function html(int $status, string $body): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
