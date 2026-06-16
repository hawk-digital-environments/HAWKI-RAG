<?php

declare(strict_types=1);

namespace App\Services\Rag;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class RagBridgeHealthClient
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
        private RagLatencyTimer $timer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function health(bool $includeRuntime = false): array
    {
        $baseUrl = rtrim((string) $this->config->get('config.base_url', 'http://hawki_rag_bridge:8000'), '/');
        $endpoint = $baseUrl.'/health?runtime='.($includeRuntime ? 'true' : 'false');

        try {
            $start = $this->timer->start();
            $response = $this->http->connectTimeout(2)->timeout(10)->get($endpoint);

            return [
                'ok' => $response->successful() && (bool) ($response->json('ok') ?? true),
                'status' => $response->status(),
                'latency_ms' => $this->timer->elapsedMs($start),
                'endpoint' => $endpoint,
                'body' => $response->json(),
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'status' => 502,
                'latency_ms' => null,
                'endpoint' => $endpoint,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $health
     * @return array<string, mixed>|null
     */
    public function runtime(array $health): ?array
    {
        $body = $health['body'] ?? null;

        return is_array($body) && is_array($body['runtime'] ?? null)
            ? $body['runtime']
            : null;
    }
}
