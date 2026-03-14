<?php
declare(strict_types=1);
namespace Relay\Handler\Bundle;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Relay\Repository\BundleRepository;
use Relay\Repository\DeviceKeyRepository;
use Slim\Psr7\Response;
final class ListBundlesHandler
{
    public function __construct(
        private readonly BundleRepository $bundles,
        private readonly DeviceKeyRepository $deviceKeys,
    ) {}
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $accountId = $request->getAttribute('account_id');
        $keys = $this->deviceKeys->listForAccount($accountId);
        $verifiedKeys = array_column(
            array_filter($keys, fn($k) => (bool) $k['verified']),
            'device_public_key'
        );
        $bundles = $this->bundles->listForRecipientKeys($verifiedKeys);
        $response = new Response(200);
        $response->getBody()->write(json_encode([
            'data' => array_map(fn($b) => [
                'bundle_id' => $b['bundle_id'],
                'workspace_id' => $b['workspace_id'],
                'sender_device_key' => $b['sender_device_key'],
                'mode' => $b['mode'],
                'size_bytes' => (int) $b['size_bytes'],
                'created_at' => $b['created_at'],
            ], $bundles),
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
