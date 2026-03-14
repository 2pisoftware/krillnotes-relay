<?php
declare(strict_types=1);
namespace Relay\Handler\Account;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Relay\Repository\DeviceKeyRepository;
use Slim\Psr7\Response;
final class RemoveDeviceHandler
{
    public function __construct(private readonly DeviceKeyRepository $deviceKeys) {}
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $accountId = $request->getAttribute('account_id');
        $deviceKey = $request->getAttribute('device_key');
        $removed = $this->deviceKeys->remove($accountId, $deviceKey);
        if (!$removed) {
            $response = new Response(404);
            $response->getBody()->write(json_encode(['error' => ['code' => 'NOT_FOUND', 'message' => 'Device key not found on this account']]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        $response = new Response(200);
        $response->getBody()->write(json_encode(['data' => ['ok' => true]]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
