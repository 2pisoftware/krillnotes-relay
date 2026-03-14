<?php
declare(strict_types=1);
namespace Relay\Handler\Bundle;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Relay\Service\BundleRoutingService;
use Slim\Psr7\Response;
final class UploadBundleHandler
{
    public function __construct(
        private readonly BundleRoutingService $routing,
        private readonly array $settings,
    ) {}
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $headerJson = $body['header'] ?? '';
        $payload = $body['payload'] ?? '';
        if (!$headerJson || !$payload) {
            return $this->json(400, ['error' => ['code' => 'MISSING_FIELDS', 'message' => 'header and payload are required']]);
        }
        $payloadData = base64_decode($payload, true);
        if ($payloadData === false) {
            return $this->json(400, ['error' => ['code' => 'INVALID_PAYLOAD', 'message' => 'payload must be valid base64']]);
        }
        $maxSize = $this->settings['limits']['max_bundle_size_bytes'];
        if (strlen($payloadData) > $maxSize) {
            return $this->json(413, ['error' => ['code' => 'BUNDLE_TOO_LARGE', 'message' => "Bundle exceeds maximum size of {$maxSize} bytes"]]);
        }
        try {
            $result = $this->routing->routeBundle($headerJson, $payloadData);
        } catch (\InvalidArgumentException $e) {
            return $this->json(400, ['error' => ['code' => 'INVALID_HEADER', 'message' => $e->getMessage()]]);
        }
        return $this->json(201, ['data' => $result]);
    }
    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
