<?php

declare(strict_types=1);

namespace App\Services\Rag;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class RagHealthService
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
        private RagLatencyTimer $timer,
    ) {}

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function show(): array
    {
        $lastError = null;
        foreach ($this->candidateEndpoints() as $url) {
            try {
                $start = $this->timer->start();
                $response = $this->http->connectTimeout(2)->timeout(10)->get($url);
                $elapsedMs = $this->timer->elapsedMs($start);

                if (! $response->successful()) {
                    $lastError = [
                        'status' => $response->status(),
                        'latency_ms' => $elapsedMs,
                        'body' => $response->body(),
                        'endpoint' => $url,
                    ];
                    continue;
                }

                return [
                    'status' => 200,
                    'payload' => [
                        'ok' => true,
                        'status' => $response->status(),
                        'latency_ms' => $elapsedMs,
                        'endpoint' => $url,
                        'data' => $response->json(),
                    ],
                ];
            } catch (\Throwable $exception) {
                $lastError = [
                    'status' => 502,
                    'latency_ms' => null,
                    'body' => $exception->getMessage(),
                    'endpoint' => $url,
                ];
            }
        }

        if ($lastError !== null) {
            return [
                'status' => 502,
                'payload' => [
                    'ok' => false,
                    'status' => $lastError['status'],
                    'latency_ms' => $lastError['latency_ms'],
                    'endpoint' => $lastError['endpoint'],
                    'body' => $lastError['body'],
                ],
            ];
        }

        return [
            'status' => 502,
            'payload' => [
                'ok' => false,
                'message' => 'No RAG health endpoints were configured.',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function candidateEndpoints(): array
    {
        $bridgeBase = (string) $this->config->get(
            'config.hawki_rag_bridge_url',
            $this->config->get('config.base_url', 'http://hawki_rag_bridge:8000'),
        );
        $primaryBase = (string) $this->config->get('config.rag_api_url', '');

        return array_values(array_unique(array_filter([
            rtrim($bridgeBase, '/').'/health',
            $primaryBase !== '' ? rtrim($primaryBase, '/').'/health' : null,
        ])));
    }
}
