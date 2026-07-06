<?php

declare(strict_types=1);

namespace App\Services\Heap;

use App\Models\Dataset;
use App\Services\Heap\Repositories\HeapActivityRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class HeapGraphStatsService
{
    public function __construct(
        private HeapActivityRepository $heaps,
        private ConfigRepository $config,
        private HttpFactory $http,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(Dataset $heap): array
    {
        $baseUrl = rtrim((string) $this->config->get('config.neo4j_http_url', 'http://hawki_rag_neo4j:7474'), '/');
        $database = trim((string) $this->config->get('config.neo4j_database', 'neo4j')) ?: 'neo4j';
        $endpoint = $baseUrl.'/db/'.rawurlencode($database).'/tx/commit';
        $documentJobIds = $this->heaps->documentExternalIds($heap);

        try {
            $response = $this->http->timeout(4)
                ->withBasicAuth((string) $this->config->get('config.neo4j_user', 'neo4j'), (string) $this->config->get('config.neo4j_password', ''))
                ->post($endpoint, [
                    'statements' => [[
                        'statement' => <<<'CYPHER'
MATCH (n)
WHERE n.dataset_id = $dataset_id
   OR n.dataset = $dataset_id
   OR n.namespace = $namespace
   OR n.neo4j_namespace = $namespace
   OR any(doc_id IN $document_job_ids WHERE doc_id IN coalesce(n.doc_ids, []))
WITH count(n) AS nodes
MATCH ()-[r]->()
WHERE r.dataset_id = $dataset_id
   OR r.dataset = $dataset_id
   OR r.namespace = $namespace
   OR r.neo4j_namespace = $namespace
   OR r.doc_id IN $document_job_ids
   OR any(doc_id IN $document_job_ids WHERE doc_id IN coalesce(r.doc_ids, []))
RETURN nodes, count(r) AS relationships
CYPHER,
                        'parameters' => [
                            'dataset_id' => $heap->dataset_id,
                            'namespace' => $heap->neo4j_namespace,
                            'document_job_ids' => $documentJobIds,
                        ],
                    ]],
                ]);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'namespace' => $heap->neo4j_namespace,
                    'nodes' => null,
                    'relationships' => null,
                    'error' => 'Neo4j HTTP '.$response->status(),
                ];
            }

            $errors = $response->json('errors') ?? [];
            if ($errors !== []) {
                return [
                    'ok' => false,
                    'namespace' => $heap->neo4j_namespace,
                    'nodes' => null,
                    'relationships' => null,
                    'error' => $errors[0]['message'] ?? 'Neo4j query failed.',
                ];
            }

            $row = $response->json('results.0.data.0.row') ?? [0, 0];

            return [
                'ok' => true,
                'namespace' => $heap->neo4j_namespace,
                'nodes' => (int) ($row[0] ?? 0),
                'relationships' => (int) ($row[1] ?? 0),
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'namespace' => $heap->neo4j_namespace,
                'nodes' => null,
                'relationships' => null,
                'error' => $exception->getMessage(),
            ];
        }
    }
}
