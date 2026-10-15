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
        private ConfigRepository $config,
        private HttpFactory $http,
        private Neo4jQueryClient $neo4j,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function deleteStorage(Dataset $dataset): array
    {
        return [
            'dataset_id' => $dataset->dataset_id,
            'qdrant' => $this->verifyQdrantCleanup($dataset),
            'neo4j' => $this->verifyNeo4jCleanup($dataset),
        ];
    }

    /** Final verification only: managed document operations own all sink mutations. */
    private function verifyQdrantCleanup(Dataset $dataset): array
    {
        $collection = trim((string) $dataset->qdrant_collection);
        if ($collection === '') {
            return ['ok' => true, 'collection' => '', 'remaining_points' => 0];
        }
        $baseUrl = rtrim((string) $this->config->get('config.qdrant_http_url', 'http://qdrant:6333'), '/');
        try {
            $response = $this->http->timeout(10)->post($baseUrl.'/collections/'.rawurlencode($collection).'/points/count', [
                'exact' => true,
                'filter' => ['must' => [['key' => 'dataset_id', 'match' => ['value' => $dataset->dataset_id]]]],
            ]);
            $count = $response->status() === 404 ? 0 : $response->json('result.count');
            $verified = $response->status() === 404 || ($response->successful() && is_int($count) && $count === 0);

            return ['ok' => $verified, 'collection' => $collection, 'remaining_points' => $count,
                'message' => $verified ? 'Owned dataset points are verified absent; shared collection is retained.' : 'Owned points remain or their count is unconfirmed; retry scoped document cleanup.'];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'collection' => $collection, 'message' => $exception->getMessage()];
        }
    }

    /** Verify exact canonical scope; never strip another document's provenance. */
    private function verifyNeo4jCleanup(Dataset $dataset): array
    {
        $namespace = trim((string) $dataset->neo4j_namespace);
        if ($namespace === '') {
            return ['ok' => true, 'namespace' => '', 'nodes' => 0, 'relationships' => 0];
        }
        try {
            $payload = $this->neo4j->postStatements([
                ['statement' => 'MATCH (n:Entity) WHERE n.dataset_id = $dataset_id AND n.neo4j_namespace = $namespace RETURN count(n)',
                    'parameters' => ['dataset_id' => $dataset->dataset_id, 'namespace' => $namespace]],
                ['statement' => 'MATCH ()-[r:REL]->() WHERE r.dataset_id = $dataset_id AND r.neo4j_namespace = $namespace RETURN count(r)',
                    'parameters' => ['dataset_id' => $dataset->dataset_id, 'namespace' => $namespace]],
            ]);
            $nodes = $payload['results'][0]['data'][0]['row'][0] ?? null;
            $relationships = $payload['results'][1]['data'][0]['row'][0] ?? null;

            return ['ok' => $nodes === 0 && $relationships === 0, 'namespace' => $namespace,
                'nodes' => $nodes, 'relationships' => $relationships];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'namespace' => $namespace, 'nodes' => null, 'relationships' => null,
                'error' => $exception->getMessage()];
        }
    }
}
