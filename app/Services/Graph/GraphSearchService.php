<?php

declare(strict_types=1);

namespace App\Services\Graph;

use App\Services\RagSearch\RagSearcher;

class GraphSearchService
{
    public function __construct(
        private readonly Neo4jClient $neo4j,
        private readonly RagSearcher $ragSearcher,
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
            $index = $this->fulltextIndexName();
            if ($index) {
                $records = $this->neo4j->run(
                    "CALL db.index.fulltext.queryNodes(\$index, \$query) YIELD node, score
                     WHERE coalesce(node.name, node.entity_id) IS NOT NULL
                     RETURN node, score
                     ORDER BY score DESC
                     LIMIT \$limit",
                    ['index' => $index, 'query' => $this->fulltextQuery($query), 'limit' => $limit]
                );

                return [
                    'ok' => true,
                    'results' => $this->nodeSearchResults($records),
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
            'results' => $this->nodeSearchResults($records),
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
            $vectorIndexes = $this->vectorIndexes();
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
                'results' => $this->nodeSearchResults($records),
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

    private function nodeSearchResults(array $records): array
    {
        return array_values(array_filter(array_map(function (array $record): ?array {
            $graphNodes = $record['_graph']['nodes'] ?? [];
            $node = $this->normalizeNodes($graphNodes)[0] ?? null;
            if ($node) {
                $node['score'] = $record['score'] ?? null;
                $node['highlighted'] = true;
            }

            return $node;
        }, $records)));
    }

    private function normalizeNodes(array $nodes): array
    {
        $normalized = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $id = (string) ($node['elementId'] ?? $node['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $properties = is_array($node['properties'] ?? null) ? $node['properties'] : [];
            unset($properties['embedding'], $properties['vector']);
            $labels = array_values($node['labels'] ?? []);
            $docIds = $this->stringList($properties['doc_ids'] ?? $properties['doc_id'] ?? []);
            $normalized[$id] = [
                'id' => $id,
                'label' => (string) ($properties['name'] ?? $properties['entity_id'] ?? $id),
                'type' => (string) ($labels[0] ?? 'Entity'),
                'properties' => $properties,
                'score' => $node['score'] ?? null,
                'source_document_ids' => $docIds,
                'highlighted' => false,
            ];
        }

        return array_values($normalized);
    }

    private function fulltextIndexName(): ?string
    {
        $records = $this->neo4j->run('SHOW INDEXES YIELD name, type, entityType, labelsOrTypes, properties RETURN name, type, entityType, labelsOrTypes, properties', [], false);
        foreach ($records as $record) {
            if (($record['type'] ?? null) !== 'FULLTEXT' || ($record['entityType'] ?? null) !== 'NODE') {
                continue;
            }
            $labels = $record['labelsOrTypes'] ?? [];
            $properties = $record['properties'] ?? [];
            if (in_array('Entity', $labels, true) && (in_array('name', $properties, true) || in_array('entity_id', $properties, true))) {
                return (string) $record['name'];
            }
        }

        return null;
    }

    private function vectorIndexes(): array
    {
        return array_values(array_filter(
            $this->neo4j->run('SHOW INDEXES YIELD name, type, entityType, labelsOrTypes, properties RETURN name, type, entityType, labelsOrTypes, properties', [], false),
            static fn (array $record): bool => ($record['type'] ?? null) === 'VECTOR' && ($record['entityType'] ?? null) === 'NODE'
        ));
    }

    private function fulltextQuery(string $query): string
    {
        $terms = preg_split('/\s+/', trim($query)) ?: [];
        $terms = array_values(array_filter(array_map(
            static fn (string $term): string => preg_replace('/[^[:alnum:]_\-]+/u', '', $term) ?? '',
            $terms
        )));

        return $terms === [] ? $query : implode(' AND ', array_map(static fn (string $term): string => $term.'*', $terms));
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

    private function stringList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value)));
        }
        if ($value === null || $value === '') {
            return [];
        }

        return [(string) $value];
    }
}
