<?php

declare(strict_types=1);

namespace App\Services\Graph;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

class Neo4jClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(string $statement, array $parameters = [], bool $includeGraph = true): array
    {
        $payload = $this->postStatements([[
            'statement' => $statement,
            'parameters' => $parameters,
            'resultDataContents' => $includeGraph ? ['row', 'graph'] : ['row'],
        ]]);

        $data = $payload['results'][0]['data'] ?? [];
        $columns = $payload['results'][0]['columns'] ?? [];

        return array_map(static function (array $record) use ($columns): array {
            $row = $record['row'] ?? [];
            $out = [];
            foreach ($columns as $index => $column) {
                $out[$column] = $row[$index] ?? null;
            }
            $out['_graph'] = $record['graph'] ?? [];
            $out['_meta'] = $record['meta'] ?? [];

            return $out;
        }, $data);
    }

    public function clearAll(): array
    {
        try {
            $payload = $this->postStatements([['statement' => 'MATCH (n) DETACH DELETE n']]);
            $stats = $payload['results'][0]['stats'] ?? null;

            return [
                'ok' => true,
                'stats' => $stats,
            ];
        } catch (\Throwable $exception) {
            $this->logger->warning('Neo4j clear exception', ['exception' => $exception]);

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function postStatements(array $statements): array
    {
        $response = $this->http->timeout(20)
            ->withBasicAuth($this->user(), $this->password())
            ->post($this->endpoint(), ['statements' => $statements]);

        if (! $response->successful()) {
            $this->logger->warning('Neo4j HTTP request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('Neo4j HTTP error '.$response->status().': '.$response->body());
        }

        $payload = $response->json() ?: [];
        $errors = $payload['errors'] ?? [];
        if (! empty($errors)) {
            throw new \RuntimeException($errors[0]['message'] ?? 'Neo4j query failed.');
        }

        return is_array($payload) ? $payload : [];
    }

    private function endpoint(): string
    {
        $baseUrl = rtrim((string) $this->config->get('config.neo4j_http_url', 'http://hawki_rag_neo4j:7474'), '/');
        $database = trim((string) $this->config->get('config.neo4j_database', 'neo4j')) ?: 'neo4j';

        return $baseUrl.'/db/'.rawurlencode($database).'/tx/commit';
    }

    private function user(): string
    {
        return (string) $this->config->get('config.neo4j_user', 'neo4j');
    }

    private function password(): string
    {
        return (string) $this->config->get('config.neo4j_password', '');
    }
}
