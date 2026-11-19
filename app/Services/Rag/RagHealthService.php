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
        $authority = $this->config->get('config.query_backend', 'python');
        if ($authority === 'python') {
            $url = $this->config->get('config.python_query_url');
            $parts = is_string($url) ? parse_url($url) : false;
            if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment']) || ($parts['path'] ?? '') !== '/v1/query') {
                return ['status' => 503, 'payload' => ['ok' => false, 'authority' => 'python',
                    'status' => 503, 'latency_ms' => null, 'endpoint' => null, 'data' => null]];
            }
            $healthUrl = substr($url, 0, -strlen('/query')).'/health';
            try {
                $start = $this->timer->start();
                $response = $this->http->connectTimeout(2)->timeout(10)->withoutRedirecting()
                    ->get($healthUrl);

                return ['status' => $response->successful() ? 200 : 503,
                    'payload' => ['ok' => $response->successful(), 'authority' => 'python',
                        'status' => $response->status(), 'latency_ms' => $this->timer->elapsedMs($start),
                        'endpoint' => $healthUrl, 'data' => $response->successful() ? $response->json() : null]];
            } catch (\Throwable) {
                return ['status' => 503, 'payload' => ['ok' => false, 'authority' => 'python',
                    'status' => 503, 'latency_ms' => null, 'endpoint' => $healthUrl, 'data' => null]];
            }
        }
        if ($authority !== 'laravel') {
            return ['status' => 503, 'payload' => ['ok' => false, 'message' => 'Invalid query authority.']];
        }
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
        $bridgeBase = (string) $this->config->get('config.hawki_rag_bridge_url', 'http://hawki_rag_bridge');

        return array_values(array_unique(array_filter([
            rtrim($bridgeBase, '/').'/health?runtime=false',
        ])));
    }
}
