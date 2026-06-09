<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Smoke;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

readonly class PipelineSmokeExternalVerifier
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
    ) {
    }

    public function defaultGraphEnabled(): bool
    {
        return filter_var($this->config->get('communication.rabbitmq.pipeline_ingestion.graph'), FILTER_VALIDATE_BOOLEAN);
    }

    public function verifyQdrantPoint(string $collection, string $jobId, string $taskId, int $timeout): int
    {
        $url = rtrim((string) $this->config->get('config.qdrant_http_url'), '/');
        if ($url === '') {
            $qdrant = $this->config->get('model_providers.vector_stores.qdrant', []);
            $url = sprintf('%s://%s:%s', $qdrant['scheme'] ?? 'http', $qdrant['host'] ?? 'qdrant', $qdrant['port'] ?? 6333);
        }

        foreach ([
            ['job_id', $jobId],
            ['doc_id', $jobId],
            ['task_id', $taskId],
        ] as [$key, $value]) {
            $request = $this->http->timeout($timeout)->connectTimeout($timeout)->acceptJson()->asJson();
            if ($apiKey = $this->config->get('model_providers.vector_stores.qdrant.api_key')) {
                $request = $request->withHeader('api-key', (string) $apiKey);
            }

            $response = $request->post($url . '/collections/' . rawurlencode($collection) . '/points/scroll', [
                'limit' => 3,
                'with_payload' => true,
                'with_vector' => false,
                'filter' => [
                    'must' => [[
                        'key' => $key,
                        'match' => ['value' => $value],
                    ]],
                ],
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException("Qdrant returned HTTP {$response->status()} for collection {$collection}.");
            }

            $points = $response->json('result.points') ?? [];
            if (is_array($points) && count($points) > 0) {
                return count($points);
            }
        }

        throw new \RuntimeException("No Qdrant point found for job {$jobId} or task {$taskId} in collection {$collection}.");
    }

    /**
     * @return array{nodes:int,relationships:int}
     */
    public function verifyNeo4jGraph(string $documentJobId, string $taskId, int $timeout): array
    {
        $url = rtrim((string) $this->config->get('config.neo4j_http_url'), '/');
        $database = trim((string) $this->config->get('config.neo4j_database', 'neo4j')) ?: 'neo4j';
        $response = $this->http->timeout($timeout)
            ->connectTimeout($timeout)
            ->withBasicAuth((string) $this->config->get('config.neo4j_user'), (string) $this->config->get('config.neo4j_password'))
            ->acceptJson()
            ->asJson()
            ->post($url . '/db/' . rawurlencode($database) . '/tx/commit', [
                'statements' => [[
                    'statement' => <<<'CYPHER'
MATCH (n)
WHERE n.doc_id = $doc_id
   OR n.job_id = $doc_id
   OR n.task_id = $task_id
   OR $doc_id IN coalesce(n.doc_ids, [])
WITH count(n) AS nodes
OPTIONAL MATCH ()-[r]->()
WHERE r.doc_id = $doc_id
   OR r.job_id = $doc_id
   OR r.task_id = $task_id
   OR $doc_id IN coalesce(r.doc_ids, [])
RETURN nodes, count(r) AS relationships
CYPHER,
                    'parameters' => [
                        'doc_id' => $documentJobId,
                        'task_id' => $taskId,
                    ],
                ]],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("Neo4j returned HTTP {$response->status()}.");
        }

        $errors = $response->json('errors') ?? [];
        if ($errors !== []) {
            throw new \RuntimeException('Neo4j returned errors: ' . json_encode($errors, JSON_UNESCAPED_SLASHES));
        }

        $row = $response->json('results.0.data.0.row') ?? [0, 0];
        $nodes = (int) ($row[0] ?? 0);
        $relationships = (int) ($row[1] ?? 0);
        if ($nodes < 1 && $relationships < 1) {
            throw new \RuntimeException("No Neo4j graph records found for smoke document {$documentJobId}.");
        }

        return [
            'nodes' => $nodes,
            'relationships' => $relationships,
        ];
    }
}
