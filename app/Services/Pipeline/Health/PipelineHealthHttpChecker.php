<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Health;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class PipelineHealthHttpChecker
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
    ) {
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function reachabilityCheck(string $name, string $url, int $timeout, string $detail, string $fix): array
    {
        try {
            $response = $this->http->timeout($timeout)->connectTimeout($timeout)->acceptJson()->get($url);
            if ($response->status() < 500) {
                return $this->ok($name, "{$detail} Service reachable at {$url} with HTTP {$response->status()}.");
            }

            return $this->failureResult($name, "HTTP {$response->status()} from {$url}.", $fix);
        } catch (\Throwable $exception) {
            return $this->failureResult($name, $exception->getMessage(), $fix);
        }
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function successCheck(string $name, string $url, int $timeout, string $detail, string $fix): array
    {
        try {
            $response = $this->http->timeout($timeout)->connectTimeout($timeout)->acceptJson()->get($url);
            if ($response->successful()) {
                return $this->ok($name, "{$detail} Service healthy at {$url}.");
            }

            return $this->failureResult($name, "HTTP {$response->status()} from {$url}.", $fix);
        } catch (\Throwable $exception) {
            return $this->failureResult($name, $exception->getMessage(), $fix);
        }
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function qdrant(int $timeout): array
    {
        $url = rtrim((string) $this->config->get('config.qdrant_http_url'), '/');
        if ($url === '') {
            $qdrant = $this->config->get('model_providers.vector_stores.qdrant', []);
            $url = sprintf('%s://%s:%s', $qdrant['scheme'] ?? 'http', $qdrant['host'] ?? 'qdrant', $qdrant['port'] ?? 6333);
        }

        try {
            $request = $this->http->timeout($timeout)->connectTimeout($timeout)->acceptJson();
            if ($apiKey = $this->config->get('model_providers.vector_stores.qdrant.api_key')) {
                $request = $request->withHeader('api-key', (string) $apiKey);
            }

            $response = $request->get($url.'/collections');
            if ($response->successful()) {
                return $this->ok('Qdrant', 'Connected to '.$url.'.');
            }

            return $this->failureResult(
                'Qdrant',
                "HTTP {$response->status()} from {$url}/collections.",
                'Start qdrant and verify QDRANT_HTTP_URL, QDRANT_HOST, QDRANT_PORT, and QDRANT_API_KEY.',
            );
        } catch (\Throwable $exception) {
            return $this->failureResult(
                'Qdrant',
                $exception->getMessage(),
                'Start qdrant and verify QDRANT_HTTP_URL, QDRANT_HOST, QDRANT_PORT, and QDRANT_API_KEY.',
            );
        }
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function neo4j(int $timeout): array
    {
        $url = rtrim((string) $this->config->get('config.neo4j_http_url'), '/');
        $database = trim((string) $this->config->get('config.neo4j_database', 'neo4j')) ?: 'neo4j';

        try {
            $response = $this->http->timeout($timeout)
                ->connectTimeout($timeout)
                ->withBasicAuth((string) $this->config->get('config.neo4j_user'), (string) $this->config->get('config.neo4j_password'))
                ->acceptJson()
                ->asJson()
                ->post($url."/db/{$database}/tx/commit", [
                    'statements' => [[
                        'statement' => 'RETURN 1 AS ok',
                    ]],
                ]);

            $errors = $response->json('errors') ?? [];
            if ($response->successful() && $errors === []) {
                return $this->ok('Neo4j', "Connected to {$url}, database {$database}.");
            }

            return $this->failureResult(
                'Neo4j',
                $response->successful()
                    ? 'Neo4j returned errors: '.json_encode($errors, JSON_UNESCAPED_SLASHES)
                    : "HTTP {$response->status()} from {$url}.",
                'Start Neo4j and verify NEO4J_HTTP_URL, NEO4J_USER, NEO4J_PASSWORD, and NEO4J_DATABASE.',
            );
        } catch (\Throwable $exception) {
            return $this->failureResult(
                'Neo4j',
                $exception->getMessage(),
                'Start Neo4j and verify NEO4J_HTTP_URL, NEO4J_USER, NEO4J_PASSWORD, and NEO4J_DATABASE.',
            );
        }
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    private function ok(string $name, string $detail): array
    {
        return [
            'name' => $name,
            'status' => 'ok',
            'detail' => $detail,
            'fix' => '',
        ];
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    private function failureResult(string $name, string $detail, string $fix): array
    {
        return [
            'name' => $name,
            'status' => 'fail',
            'detail' => $detail,
            'fix' => $fix,
        ];
    }
}
