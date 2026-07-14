<?php

declare(strict_types=1);

namespace App\Services\Graph;

use App\Models\User;
use App\Services\Authorization\DatasetQueryAuthorizationService;

class GraphSearchService
{
    public function __construct(
        private readonly Neo4jClient $neo4j,
        private readonly DatasetQueryAuthorizationService $authorization,
        private readonly GraphResultNormalizer $normalizer,
        private readonly GraphCypherSearch $cypherSearch,
    ) {}

    public function searchEntities(string $query, int $limit = 12): array
    {
        $query = trim($query);
        $limit = max(1, min(50, $limit));
        if ($query === '') {
            return ['ok' => true, 'results' => [], 'warnings' => []];
        }

        $warnings = [];
        try {
            $index = $this->cypherSearch->fulltextIndexName();
            if ($index) {
                $records = $this->neo4j->run(
                    'CALL db.index.fulltext.queryNodes($index, $query) YIELD node, score
                     WHERE coalesce(node.name, node.entity_id) IS NOT NULL
                     RETURN node, score
                     ORDER BY score DESC
                     LIMIT $limit',
                    ['index' => $index, 'query' => $this->cypherSearch->fulltextQuery($query), 'limit' => $limit]
                );

                return [
                    'ok' => true,
                    'results' => $this->normalizer->nodeSearchResults($records),
                    'search_mode' => 'fulltext',
                    'index' => $index,
                    'warnings' => [],
                ];
            }
            $warnings[] = 'No Neo4j fulltext node index was found; using case-insensitive contains search.';
        } catch (\Throwable $exception) {
            $warnings[] = 'Neo4j fulltext search failed; using contains fallback. '.$exception->getMessage();
        }

        $records = $this->neo4j->run(
            "MATCH (node)
             WHERE coalesce(node.name, node.entity_id) IS NOT NULL
               AND toLower(coalesce(node.name, node.entity_id, '')) CONTAINS toLower(\$query)
             WITH node,
               CASE
                 WHEN toLower(coalesce(node.name, node.entity_id, '')) = toLower(\$query) THEN 10.0
                 WHEN toLower(coalesce(node.name, node.entity_id, '')) STARTS WITH toLower(\$query) THEN 5.0
                 ELSE 1.0
               END AS score
             RETURN node, score
             ORDER BY score DESC, coalesce(node.name, node.entity_id)
             LIMIT \$limit",
            ['query' => $query, 'limit' => $limit]
        );

        return [
            'ok' => true,
            'results' => $this->normalizer->nodeSearchResults($records),
            'search_mode' => 'contains',
            'warnings' => $warnings,
        ];
    }

    public function semanticSearch(User $user, string $datasetId, string $query, int $limit = 8): array
    {
        $scope = $this->authorization->authorize($user, $datasetId);
        $query = trim($query);
        if ($query === '') {
            return [
                'ok' => true,
                'dataset_id' => $scope->datasetId,
                'results' => [],
                'warnings' => [],
            ];
        }

        return [
            'ok' => false,
            'status' => 409,
            'error' => 'dataset_graph_not_ready',
            'message' => 'Dataset-scoped graph retrieval is disabled until Neo4j records carry enforceable dataset scope.',
            'dataset_id' => $scope->datasetId,
            'results' => [],
            'warnings' => ['No global graph fallback was executed.'],
        ];
    }
}
