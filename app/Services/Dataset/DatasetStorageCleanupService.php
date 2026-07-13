<?php

declare(strict_types=1);

namespace App\Services\Dataset;

use App\Models\Dataset;
use App\Services\Graph\Neo4jQueryClient;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class DatasetStorageCleanupService
{
    public function __construct(
        private DatasetRepository $datasets,
        private ConfigRepository $config,
        private HttpFactory $http,
        private Neo4jQueryClient $neo4j,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteStorage(Dataset $dataset): array
    {
        return [
            'dataset_id' => $dataset->dataset_id,
            'qdrant' => $this->deleteQdrantCollection($dataset),
            'neo4j' => $this->deleteNeo4jData($dataset),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteQdrantCollection(Dataset $dataset): array
    {
        $collection = trim((string) $dataset->qdrant_collection);
        if ($collection === '') {
            return [
                'ok' => true,
                'collection' => '',
                'message' => 'No Qdrant collection is recorded for this dataset.',
            ];
        }

        $baseUrl = rtrim((string) $this->config->get('config.qdrant_http_url', 'http://qdrant:6333'), '/');

        try {
            $response = $this->http->timeout(10)->delete($baseUrl.'/collections/'.rawurlencode($collection));
            if ($response->status() === 404) {
                return [
                    'ok' => true,
                    'collection' => $collection,
                    'message' => 'Qdrant collection was already absent.',
                ];
            }

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'collection' => $collection,
                    'message' => "Qdrant returned HTTP {$response->status()} while deleting {$collection}.",
                    'error' => $response->body(),
                ];
            }

            return [
                'ok' => true,
                'collection' => $collection,
                'message' => "Deleted Qdrant collection {$collection}.",
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'collection' => $collection,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteNeo4jData(Dataset $dataset): array
    {
        $documentJobIds = $this->datasets->documentExternalIds($dataset);
        $parameters = [
            'dataset_id' => $dataset->dataset_id,
            'namespace' => $dataset->neo4j_namespace,
            'document_job_ids' => $documentJobIds,
        ];

        try {
            $payload = $this->neo4j->postStatements([
                [
                    'statement' => <<<'CYPHER'
MATCH ()-[r]->()
WHERE r.dataset_id = $dataset_id
   OR r.dataset = $dataset_id
   OR r.namespace = $namespace
   OR r.neo4j_namespace = $namespace
   OR r.doc_id IN $document_job_ids
   OR any(doc_id IN $document_job_ids WHERE doc_id IN coalesce(r.doc_ids, []))
SET r.doc_ids = [doc_id IN coalesce(r.doc_ids, []) WHERE NOT doc_id IN $document_job_ids]
SET r.doc_id = CASE
  WHEN r.doc_id IN $document_job_ids THEN head([doc_id IN coalesce(r.doc_ids, []) WHERE NOT doc_id IN $document_job_ids])
  ELSE r.doc_id
END
WITH r
WHERE r.dataset_id = $dataset_id
   OR r.dataset = $dataset_id
   OR r.namespace = $namespace
   OR r.neo4j_namespace = $namespace
   OR (coalesce(size(r.doc_ids), 0) = 0 AND r.doc_id IS NULL)
WITH collect(r) AS relationships
FOREACH (relationship IN relationships | DELETE relationship)
RETURN size(relationships) AS deleted_relationships
CYPHER,
                    'parameters' => $parameters,
                ],
                [
                    'statement' => <<<'CYPHER'
MATCH (n)
WHERE n.dataset_id = $dataset_id
   OR n.dataset = $dataset_id
   OR n.namespace = $namespace
   OR n.neo4j_namespace = $namespace
   OR n.doc_id IN $document_job_ids
   OR any(doc_id IN $document_job_ids WHERE doc_id IN coalesce(n.doc_ids, []))
WITH n,
     (n.dataset_id = $dataset_id
      OR n.dataset = $dataset_id
      OR n.namespace = $namespace
      OR n.neo4j_namespace = $namespace) AS dataset_match,
     [doc_id IN coalesce(n.doc_ids, []) WHERE NOT doc_id IN $document_job_ids] AS remaining_doc_ids
SET n.doc_ids = remaining_doc_ids
SET n.doc_id = CASE
  WHEN n.doc_id IN $document_job_ids THEN head(remaining_doc_ids)
  ELSE n.doc_id
END
WITH n, dataset_match, remaining_doc_ids
WHERE dataset_match
   OR (coalesce(size(remaining_doc_ids), 0) = 0 AND n.doc_id IS NULL)
WITH collect(DISTINCT n) AS nodes
UNWIND nodes AS node
OPTIONAL MATCH (node)-[attached]-()
WITH nodes, [relationship IN collect(DISTINCT attached) WHERE relationship IS NOT NULL] AS attached_relationships
FOREACH (relationship IN attached_relationships | DELETE relationship)
FOREACH (node IN nodes | DELETE node)
RETURN size(nodes) AS deleted_nodes, size(attached_relationships) AS deleted_attached_relationships
CYPHER,
                    'parameters' => $parameters,
                ],
                [
                    'statement' => <<<'CYPHER'
MATCH (n)
WHERE n.dataset_id = $dataset_id
   OR n.dataset = $dataset_id
   OR n.namespace = $namespace
   OR n.neo4j_namespace = $namespace
   OR n.doc_id IN $document_job_ids
   OR any(doc_id IN $document_job_ids WHERE doc_id IN coalesce(n.doc_ids, []))
SET n.doc_ids = [doc_id IN coalesce(n.doc_ids, []) WHERE NOT doc_id IN $document_job_ids]
SET n.doc_id = CASE
  WHEN n.doc_id IN $document_job_ids THEN head([doc_id IN coalesce(n.doc_ids, []) WHERE NOT doc_id IN $document_job_ids])
  ELSE n.doc_id
END
WITH n
WHERE NOT (n)--() AND (coalesce(size(n.doc_ids), 0) = 0 AND n.doc_id IS NULL)
WITH collect(n) AS nodes
FOREACH (node IN nodes | DELETE node)
RETURN size(nodes) AS deleted_nodes
CYPHER,
                    'parameters' => $parameters,
                ],
            ]);

            return [
                'ok' => true,
                'namespace' => $dataset->neo4j_namespace,
                'document_job_ids' => count($documentJobIds),
                'relationships' => (int) ($payload['results'][0]['data'][0]['row'][0] ?? 0)
                    + (int) ($payload['results'][1]['data'][0]['row'][1] ?? 0),
                'nodes' => (int) ($payload['results'][1]['data'][0]['row'][0] ?? 0)
                    + (int) ($payload['results'][2]['data'][0]['row'][0] ?? 0),
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'namespace' => $dataset->neo4j_namespace,
                'document_job_ids' => count($documentJobIds),
                'nodes' => null,
                'relationships' => null,
                'error' => $exception->getMessage(),
            ];
        }
    }
}
