<?php
declare(strict_types=1);
namespace Relay\Handler\Bundle;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Relay\Repository\BundleRepository;
use Relay\Repository\DeviceKeyRepository;
use Relay\Repository\AccountRepository;
use Relay\Service\StorageService;
use Slim\Psr7\Response;
final class DeleteBundleHandler
{
    public function __construct(
        private readonly BundleRepository $bundles,
        private readonly DeviceKeyRepository $deviceKeys,
        private readonly AccountRepository $accounts,
        private readonly StorageService $storage,
    ) {}
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $accountId = $request->getAttribute('account_id');
        $bundleId = $request->getAttribute('bundle_id');
        $bundle = $this->bundles->findById($bundleId);
        if ($bundle === null) {
            $response = new Response(404);
            $response->getBody()->write(json_encode(['error' => ['code' => 'NOT_FOUND', 'message' => 'Bundle not found']]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        $ownerCheck = $this->deviceKeys->findByKey($bundle['recipient_device_key']);
        if ($ownerCheck === null || $ownerCheck['account_id'] !== $accountId) {
            $response = new Response(403);
            $response->getBody()->write(json_encode(['error' => ['code' => 'FORBIDDEN', 'message' => 'Not your bundle']]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        $size = (int) $bundle['size_bytes'];
        $blobPath = $this->bundles->delete($bundleId);
        if ($blobPath !== null) {
            $this->storage->delete($blobPath);
            $this->accounts->updateStorageUsed($accountId, -$size);
        }
        $response = new Response(200);
        $response->getBody()->write(json_encode(['data' => ['ok' => true]]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
