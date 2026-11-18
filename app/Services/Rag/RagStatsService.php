<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Services\Dataset\DatasetRepository;
use App\Services\Graph\GraphOpsBackend;
use App\Services\Graph\Neo4jClient;
use App\Services\Graph\PythonGraphOpsClient;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;

readonly class RagStatsService
{
    public function __construct(private Repository $config, private Factory $http,
        private GraphOpsBackend $backend, private PythonGraphOpsClient $python,
        private DatasetRepository $datasets, private Neo4jClient $neo4j) {}

    public function show(): array
    {
        if (! $this->backend->valid()) {
            return ['ok' => false, 'status' => 503, 'error' => 'graph_ops_configuration_invalid'];
        }
        if ($this->backend->value() === 'python' || $this->config->get('config.dataset_backend') === 'python') {
            return $this->python->request('stats', manager: true);
        }
        $collections = [];
        $entities = $triplets = 0;
        try {
            foreach ($this->datasets->activeGraphScopes() as $dataset) {
                $collection = trim((string) $dataset->qdrant_collection);
                if ($collection !== '') {
                    $count = $this->http->timeout(3)->withoutRedirecting()
                        ->post(rtrim($this->config->get('config.qdrant_http_url'), '/').'/collections/'.rawurlencode($collection).'/points/count', ['exact' => true]);
                    if (! $count->successful() || ! is_int($count->json('result.count'))) {
                        throw new \RuntimeException('Owned collection count is unavailable.');
                    }
                    $collections[] = ['dataset_id' => $dataset->dataset_id, 'name' => $collection, 'count' => $count->json('result.count')];
                }
                if (trim((string) $dataset->neo4j_namespace) !== '') {
                    $scope = ['dataset_id' => $dataset->dataset_id, 'neo4j_namespace' => $dataset->neo4j_namespace];
                    $nodes = $this->neo4j->run('MATCH (n) WHERE n.dataset_id=$dataset_id AND n.neo4j_namespace=$neo4j_namespace RETURN count(n) AS count', $scope, false);
                    $edges = $this->neo4j->run('MATCH (s)-[r]->(o) WHERE s.dataset_id=$dataset_id AND s.neo4j_namespace=$neo4j_namespace AND o.dataset_id=$dataset_id AND o.neo4j_namespace=$neo4j_namespace AND r.dataset_id=$dataset_id AND r.neo4j_namespace=$neo4j_namespace RETURN count(r) AS count', $scope, false);
                    $entities += (int) ($nodes[0]['count'] ?? 0);
                    $triplets += (int) ($edges[0]['count'] ?? 0);
                }
            }
        } catch (\Throwable) {
            return ['ok' => false, 'status' => 503, 'qdrant' => ['ok' => false], 'neo4j' => ['ok' => false]];
        }

        return ['ok' => true, 'authority' => 'laravel', 'qdrant' => ['ok' => true, 'collections' => $collections],
            'neo4j' => ['ok' => true, 'entities' => $entities, 'triplets' => $triplets]];
    }
}
