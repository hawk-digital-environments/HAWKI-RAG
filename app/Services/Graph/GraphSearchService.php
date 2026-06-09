<?php

declare(strict_types=1);

namespace App\Services\Graph;

use App\Services\RagSearch\RagSearcher;

class GraphSearchService
{
    public function __construct(
        private readonly Neo4jClient $neo4j,
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
                    "CALL db.index.fulltext.queryNodes(\$index, \$query) YIELD node, score
                     WHERE coalesce(node.name, node.entity_id) IS NOT NULL
                     RETURN node, score
                     ORDER BY score DESC
                     LIMIT \$limit",
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

    public function semanticSearch(string $query, int $limit = 8): array
    {
        $query = trim($query);
        $limit = max(1, min(25, $limit));
        if ($query === '') {
            return ['ok' => true, 'results' => [], 'warnings' => []];
        }

        $warnings = [];
        try {
            $vectorIndexes = $this->cypherSearch->vectorIndexes();
            $warnings[] = empty($vectorIndexes)
                ? 'No Neo4j vector index was found; using the existing RAG semantic retrieval as a graph entry-point fallback.'
                : 'Neo4j vector indexes exist, but this Laravel app does not own an embedding endpoint for query vectors; using RAG semantic retrieval fallback.';
        } catch (\Throwable $exception) {
            $warnings[] = 'Unable to inspect Neo4j vector indexes: '.$exception->getMessage();
        }

        try {
            $rag = $this->ragSearcher->withQuery($query)->withTopK($limit)->execute();
            $names = $this->semanticNames($rag);
            if ($names === []) {
                return [
                    'ok' => true,
                    'results' => [],
                    'search_mode' => 'semantic-rag-fallback',
                    'warnings' => array_merge($warnings, ['Semantic retrieval returned no graph entry points.']),
                ];
            }

            $records = $this->neo4j->run(
                "UNWIND \$names AS name
                 MATCH (node)
                 WHERE coalesce(node.name, node.entity_id) IS NOT NULL
                   AND toLower(coalesce(node.name, node.entity_id, '')) = toLower(name)
                 RETURN DISTINCT node, 1.0 AS score
                 LIMIT \$limit",
                ['names' => $names, 'limit' => $limit]
            );

            return [
                'ok' => true,
                'results' => $this->normalizer->nodeSearchResults($records),
                'search_mode' => 'semantic-rag-fallback',
                'warnings' => $warnings,
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'results' => [],
                'message' => 'Semantic search failed.',
                'error' => $exception->getMessage(),
                'warnings' => $warnings,
            ];
        }
    }

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
