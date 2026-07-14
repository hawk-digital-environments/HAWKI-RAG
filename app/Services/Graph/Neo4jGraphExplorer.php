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

    public function overview(int $limit = 80): array
    {
        $limit = max(5, min(300, $limit));

        return $this->graphQuery(
            'MATCH (s)-[r]->(o)
             WHERE coalesce(s.name, s.entity_id) IS NOT NULL
               AND coalesce(o.name, o.entity_id) IS NOT NULL
             WITH s, r, o
             ORDER BY coalesce(r.updated_at, 0) DESC
             LIMIT $limit
             RETURN collect(DISTINCT s) + collect(DISTINCT o) AS nodes, collect(DISTINCT r) AS edges',
            ['limit' => $limit],
            ['mode' => 'overview']
        );
    }

    public function searchEntities(string $query, int $limit = 12): array
    {
        return $this->search->searchEntities($query, $limit);
    }

    public function semanticSearch(User $user, string $datasetId, string $query, int $limit = 8): array
    {
        return $this->search->semanticSearch($user, $datasetId, $query, $limit);
    }

    public function expand(string $nodeId, int $depth = 1, int $limit = 80): array
    {
        $depth = max(1, min(3, $depth));
        $limit = max(5, min(250, $limit));

        return $this->graphQuery(
            "MATCH (center)
             WHERE elementId(center) = \$nodeId
             MATCH path = (center)-[*1..{$depth}]-(neighbor)
             WITH path
             LIMIT \$limit
             WITH collect(path) AS paths
             WITH reduce(ns = [], path IN paths | ns + nodes(path)) AS allNodes,
                  reduce(rs = [], path IN paths | rs + relationships(path)) AS allEdges
             RETURN allNodes AS nodes, allEdges AS edges",
            ['nodeId' => $nodeId, 'limit' => $limit],
            ['mode' => 'expand', 'expanded_node_id' => $nodeId, 'depth' => $depth]
        );
    }

    public function graphForNode(string $nodeId, int $limit = 80): array
    {
        $base = $this->expand($nodeId, 1, $limit);
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

        return $this->normalizer->graph($records, $extra);
    }
}
