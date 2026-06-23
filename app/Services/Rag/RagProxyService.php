<?php

declare(strict_types=1);

namespace App\Services\Rag;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class RagProxyService
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @return array{payload: mixed, status: int}
     */
    public function query(array $data): array
    {
        $payload = [
            'query' => $data['query'],
            'top_k' => $data['top_k'] ?? 5,
            'is_optimized' => $data['is_optimized'] ?? false,
            'generate' => $data['generate'] ?? true,
            'fast_mode' => $data['fast_mode'] ?? false,
            'smart_lookup' => $data['smart_lookup'] ?? false,
        ];

        if (! empty($data['preferred_tags'])) {
            $payload['preferred_tags'] = $data['preferred_tags'];
        }

        try {
            $response = $this->http->timeout(60)->post($this->queryEndpoint(), $payload);
        } catch (\Throwable $exception) {
            return [
                'status' => 502,
                'payload' => [
                    'ok' => false,
                    'message' => 'Failed to reach HAWKI RAG bridge',
                    'error' => $exception->getMessage(),
                ],
            ];
        }

        $json = $response->json();
        if ($json === null) {
            return [
                'status' => 502,
                'payload' => [
                    'ok' => false,
                    'message' => 'HAWKI RAG bridge returned an invalid response',
                    'status' => $response->status(),
                    'body' => $response->body(),
                ],
            ];
        }

        return [
            'status' => $response->status(),
            'payload' => $json,
        ];
    }

    private function queryEndpoint(): string
    {
        return rtrim((string) $this->config->get('config.hawki_rag_bridge_url'), '/').'/query';
    }
}
