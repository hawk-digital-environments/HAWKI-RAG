<?php

declare(strict_types=1);

namespace App\Services\OpenCompat;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class OpenCompatBridgeClient
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @return array{payload: mixed, status: int}
     */
    public function post(string $path, array $payload, ?string $idempotencyKey = null, int $timeout = 60): array
    {
        try {
            $request = $this->http->timeout($timeout);
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
            }

            $response = $request->post($this->url($path), $payload);
        } catch (\Throwable $exception) {
            return $this->bridgeFailure($exception);
        }

        return $this->decoded($response->json(), $response->status(), $response->body());
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{payload: mixed, status: int}
     */
    public function put(string $path, array $payload, ?string $idempotencyKey = null, int $timeout = 60): array
    {
        try {
            $request = $this->http->timeout($timeout);
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
            }

            $response = $request->put($this->url($path), $payload);
        } catch (\Throwable $exception) {
            return $this->bridgeFailure($exception);
        }

        return $this->decoded($response->json(), $response->status(), $response->body());
    }

    /**
     * @return array{payload: mixed, status: int}
     */
    public function delete(string $path, ?string $idempotencyKey = null, int $timeout = 30): array
    {
        try {
            $request = $this->http->timeout($timeout);
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
            }

            $response = $request->delete($this->url($path));
        } catch (\Throwable $exception) {
            return $this->bridgeFailure($exception);
        }

        return $this->decoded($response->json(), $response->status(), $response->body());
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->config->get('config.hawki_rag_bridge_url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * @return array{payload: mixed, status: int}
     */
    private function decoded(mixed $json, int $status, string $body): array
    {
        if ($json === null) {
            return [
                'status' => 502,
                'payload' => [
                    'ok' => false,
                    'error' => 'bridge_invalid_response',
                    'message' => 'HAWKI RAG bridge returned a non-JSON response.',
                    'bridge_status' => $status,
                    'body' => $body,
                ],
            ];
        }

        return ['status' => $status, 'payload' => $json];
    }

    /**
     * @return array{payload: mixed, status: int}
     */
    private function bridgeFailure(\Throwable $exception): array
    {
        return [
            'status' => 502,
            'payload' => [
                'ok' => false,
                'error' => 'bridge_unavailable',
                'message' => 'Failed to reach HAWKI RAG bridge.',
                'detail' => $exception->getMessage(),
            ],
        ];
    }
}
