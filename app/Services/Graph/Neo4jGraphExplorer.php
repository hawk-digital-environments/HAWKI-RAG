<?php

declare(strict_types=1);

namespace App\Services\Graph;

use App\Models\User;

class Neo4jGraphExplorer
{
    public function __construct(
        private readonly Neo4jClient $neo4j,
        private readonly GraphResultNormalizer $normalizer,
        private readonly GraphSearchService $search,
        private readonly GraphSnapshotStore $snapshots,
    ) {}

    public function overview(int $limit = 80, array $scope = []): array
    {
        $this->requireScope($scope);
        $limit = max(5, min(300, $limit));

        return $this->graphQuery(
            'MATCH (s)-[r]->(o)
             WHERE s.dataset_id = $dataset_id AND s.neo4j_namespace = $neo4j_namespace
               AND o.dataset_id = $dataset_id AND o.neo4j_namespace = $neo4j_namespace
               AND r.dataset_id = $dataset_id AND r.neo4j_namespace = $neo4j_namespace
               AND coalesce(s.name, s.entity_id) IS NOT NULL
               AND coalesce(o.name, o.entity_id) IS NOT NULL
             WITH s, r, o
             ORDER BY coalesce(r.updated_at, 0) DESC
             LIMIT $limit
             RETURN collect(DISTINCT s) + collect(DISTINCT o) AS nodes, collect(DISTINCT r) AS edges',
            ['limit' => $limit, ...$scope],
            ['mode' => 'overview']
        );
    }

    public function searchEntities(string $query, int $limit = 12, array $scope = []): array
    {
        $this->requireScope($scope);

        return $this->search->searchEntities($query, $limit, $scope);
    }

    public function semanticSearch(User $user, string $datasetId, string $query, int $limit = 8, ?array $trustedScope = null): array
    {
        return $this->search->semanticSearch($user, $datasetId, $query, $limit, $trustedScope);
    }

    public function expand(string $nodeId, int $depth = 1, int $limit = 80, array $scope = []): array
    {
        $this->requireScope($scope);
        $depth = max(1, min(3, $depth));
        $limit = max(5, min(250, $limit));

        return $this->graphQuery(
            "MATCH (center)
             WHERE elementId(center) = \$nodeId
               AND center.dataset_id = \$dataset_id AND center.neo4j_namespace = \$neo4j_namespace
             MATCH path = (center)-[*1..{$depth}]-(neighbor)
             WHERE all(n IN nodes(path) WHERE n.dataset_id = \$dataset_id AND n.neo4j_namespace = \$neo4j_namespace)
               AND all(r IN relationships(path) WHERE r.dataset_id = \$dataset_id AND r.neo4j_namespace = \$neo4j_namespace)
             WITH path
             LIMIT \$limit
             WITH collect(path) AS paths
             WITH reduce(ns = [], path IN paths | ns + nodes(path)) AS allNodes,
                  reduce(rs = [], path IN paths | rs + relationships(path)) AS allEdges
             RETURN allNodes AS nodes, allEdges AS edges",
            ['nodeId' => $nodeId, 'limit' => $limit, ...$scope],
            ['mode' => 'expand', 'expanded_node_id' => $nodeId, 'depth' => $depth]
        );
    }

    public function graphForNode(string $nodeId, int $limit = 80, array $scope = []): array
    {
        $base = $this->expand($nodeId, 1, $limit, $scope);
        $base['entry_node_id'] = $nodeId;

        return $base;
    }

    public function saveSnapshot(array $payload): array
    {
        return $this->snapshots->save($payload);
    }

    public function snapshots(): array
    {
        return $this->snapshots->list();
    }

    public function loadSnapshot(string $id): array
    {
        return $this->snapshots->load($id);
    }

    public function deleteSnapshot(string $id): array
    {
        return $this->snapshots->delete($id);
    }

    private function graphQuery(string $statement, array $parameters = [], array $extra = []): array
    {
        $records = $this->neo4j->run($statement, $parameters);

        return $this->normalizer->graph($records, ['dataset_id' => $parameters['dataset_id'], ...$extra]);
    }

    private function requireScope(array $scope): void
    {
        abort_if(empty($scope['dataset_id']) || empty($scope['neo4j_namespace']), 409, 'Dataset graph scope is required.');
    }
}
