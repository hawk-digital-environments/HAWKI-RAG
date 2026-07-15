<?php

declare(strict_types=1);

namespace App\Services\Graph;

use App\Models\User;
use App\Services\Authorization\DatasetQueryAuthorizationService;
use App\Services\RagSearch\RagSearcher;

class GraphSearchService
{
    public function __construct(
        private readonly Neo4jClient $neo4j,
        private readonly DatasetQueryAuthorizationService $authorization,
        private readonly RagSearcher $ragSearcher,
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
        $limit = max(1, min(25, $limit));
        if ($query === '') {
            return [
                'ok' => true,
                'dataset_id' => $scope->datasetId,
                'results' => [],
                'warnings' => [],
            ];
        }

        if (! $scope->graphEnabled) {
            return [
                'ok' => false,
                'status' => 409,
                'error' => 'dataset_graph_not_ready',
                'message' => 'Dataset-scoped graph retrieval is not ready.',
                'dataset_id' => $scope->datasetId,
                'results' => [],
                'warnings' => ['No global graph fallback was executed.'],
            ];
        }

        try {
            $rag = $this->ragSearcher
                ->forDataset($user, $scope->datasetId)
                ->withQuery($query)
                ->withTopK($limit)
                ->execute();
            $names = $this->semanticNames($rag);
            if ($names === []) {
                return [
                    'ok' => true,
                    'dataset_id' => $scope->datasetId,
                    'results' => [],
                    'search_mode' => 'dataset-scoped-semantic-rag',
                    'warnings' => ['Semantic retrieval returned no graph entry points.'],
                ];
            }

            $records = $this->neo4j->run(
                <<<'CYPHER'
UNWIND $names AS name
MATCH (subject:Entity)-[relationship:REL]->(object:Entity)
WHERE subject.dataset_id = $dataset_id
  AND subject.neo4j_namespace = $neo4j_namespace
  AND relationship.dataset_id = $dataset_id
  AND relationship.neo4j_namespace = $neo4j_namespace
  AND object.dataset_id = $dataset_id
  AND object.neo4j_namespace = $neo4j_namespace
WITH name, subject, object
UNWIND [subject, object] AS node
WITH DISTINCT name, node
WHERE toLower(coalesce(node.name, node.entity_id, '')) = toLower(name)
RETURN DISTINCT node, 1.0 AS score
ORDER BY coalesce(node.name, node.entity_id)
LIMIT $limit
CYPHER,
                [
                    'names' => $names,
                    'dataset_id' => $scope->datasetId,
                    'neo4j_namespace' => $scope->neo4jNamespace,
                    'limit' => $limit,
                ]
            );

            return [
                'ok' => true,
                'dataset_id' => $scope->datasetId,
                'results' => $this->normalizer->nodeSearchResults($records),
                'search_mode' => 'dataset-scoped-semantic-rag',
                'warnings' => [],
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'message' => 'Dataset-scoped semantic graph search failed.',
                'dataset_id' => $scope->datasetId,
                'results' => [],
                'warnings' => ['No global graph fallback was executed.'],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $rag
     * @return list<string>
     */
    private function semanticNames(array $rag): array
    {
        $names = [];
        foreach (($rag['kg'] ?? []) as $fact) {
            foreach (['subject', 'object'] as $key) {
                if (! empty($fact[$key])) {
                    $names[] = (string) $fact[$key];
                }
            }
        }
        foreach (($rag['rewrite_terms'] ?? []) as $term) {
            $names[] = (string) $term;
        }
        foreach (($rag['results'] ?? []) as $hit) {
            foreach (['subject', 'object'] as $key) {
                if (! empty($hit[$key])) {
                    $names[] = (string) $hit[$key];
                }
            }
        }

        return array_values(array_unique(array_filter(array_map('trim', $names))));
    }
}
