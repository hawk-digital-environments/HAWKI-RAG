<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Health;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class Neo4jHealthCheck
{
    private const FIX = 'Start Neo4j and verify NEO4J_HTTP_URL, NEO4J_USER, NEO4J_PASSWORD, and NEO4J_DATABASE.';

    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
    ) {
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function check(int $timeout): array
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
                self::FIX,
            );
        } catch (\Throwable $exception) {
            return $this->failureResult('Neo4j', $exception->getMessage(), self::FIX);
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
