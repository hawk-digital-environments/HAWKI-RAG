<?php

namespace App\Services\GraphService;

use App\Services\RagSearch\RagSearcher;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Neo4jGraphExplorer
{
    private string $endpoint;
    private string $user;
    private string $password;

    public function __construct(private readonly RagSearcher $ragSearcher)
    {
        $baseUrl = rtrim((string) config('config.neo4j_http_url', 'http://hawki_rag_neo4j:7474'), '/');
        $database = trim((string) env('NEO4J_DATABASE', 'neo4j')) ?: 'neo4j';
        $this->endpoint = $baseUrl . '/db/' . rawurlencode($database) . '/tx/commit';
        $this->user = (string) config('config.neo4j_user', 'neo4j');
        $this->password = (string) config('config.neo4j_password', '');
    }

    public function overview(int $limit = 80): array
    {
        $limit = max(5, min(300, $limit));

        return $this->graphQuery(
            "MATCH (s)-[r]->(o)
             WHERE coalesce(s.name, s.entity_id) IS NOT NULL
               AND coalesce(o.name, o.entity_id) IS NOT NULL
             WITH s, r, o
             ORDER BY coalesce(r.updated_at, 0) DESC
             LIMIT \$limit
             RETURN collect(DISTINCT s) + collect(DISTINCT o) AS nodes, collect(DISTINCT r) AS edges",
            ['limit' => $limit],
            ['mode' => 'overview']
        );
    }

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
                $records = $this->run(
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
        } catch (\Throwable $e) {
            $warnings[] = 'Neo4j fulltext search failed; using contains fallback. ' . $e->getMessage();
        }

        $records = $this->run(
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
            if (empty($vectorIndexes)) {
                $warnings[] = 'No Neo4j vector index was found; using the existing RAG semantic retrieval as a graph entry-point fallback.';
            } else {
                $warnings[] = 'Neo4j vector indexes exist, but this Laravel app does not own an embedding endpoint for query vectors; using RAG semantic retrieval fallback.';
            }
        } catch (\Throwable $e) {
            $warnings[] = 'Unable to inspect Neo4j vector indexes: ' . $e->getMessage();
        }

        try {
            $rag = $this->ragSearcher->withQuery($query)->withTopK($limit)->execute();
            $names = [];
            foreach (($rag['kg'] ?? []) as $fact) {
                foreach (['subject', 'object'] as $key) {
                    if (!empty($fact[$key])) {
                        $names[] = (string) $fact[$key];
                    }
                }
            }
            foreach (($rag['rewrite_terms'] ?? []) as $term) {
                $names[] = (string) $term;
            }
            foreach (($rag['results'] ?? []) as $hit) {
                foreach (['subject', 'object'] as $key) {
                    if (!empty($hit[$key])) {
                        $names[] = (string) $hit[$key];
                    }
                }
            }

            $names = array_values(array_unique(array_filter(array_map('trim', $names))));
            if ($names === []) {
                return [
                    'ok' => true,
                    'results' => [],
                    'search_mode' => 'semantic-rag-fallback',
                    'warnings' => array_merge($warnings, ['Semantic retrieval returned no graph entry points.']),
                ];
            }

            $records = $this->run(
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
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'results' => [],
                'message' => 'Semantic search failed.',
                'error' => $e->getMessage(),
                'warnings' => $warnings,
            ];
        }
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
        $dir = storage_path('app/graph_snapshots');
        File::ensureDirectoryExists($dir);

        $id = now()->format('YmdHis') . '-' . Str::random(8);
        $snapshot = [
            'id' => $id,
            'name' => trim((string) ($payload['name'] ?? '')) ?: 'Graph snapshot ' . now()->format('Y-m-d H:i:s'),
            'created_at' => now()->toIso8601String(),
            'scene' => $payload['scene'] ?? [],
        ];

        File::put($dir . '/' . $id . '.json', json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return ['ok' => true, 'snapshot' => $snapshot];
    }

    public function snapshots(): array
    {
        $dir = storage_path('app/graph_snapshots');
        File::ensureDirectoryExists($dir);
        $items = [];
        foreach (File::files($dir) as $file) {
            $data = json_decode(File::get($file->getPathname()), true);
            if (is_array($data)) {
                $items[] = [
                    'id' => $data['id'] ?? $file->getBasename('.json'),
                    'name' => $data['name'] ?? $file->getBasename('.json'),
                    'created_at' => $data['created_at'] ?? null,
                ];
            }
        }

        usort($items, static fn($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return ['ok' => true, 'snapshots' => $items];
    }

    public function loadSnapshot(string $id): array
    {
        $path = storage_path('app/graph_snapshots/' . basename($id) . '.json');
        if (!File::exists($path)) {
            return ['ok' => false, 'message' => 'Snapshot not found.'];
        }

        return ['ok' => true, 'snapshot' => json_decode(File::get($path), true)];
    }

    public function deleteSnapshot(string $id): array
    {
        $path = storage_path('app/graph_snapshots/' . basename($id) . '.json');
        if (File::exists($path)) {
            File::delete($path);
        }

        return ['ok' => true];
    }

    private function graphQuery(string $statement, array $parameters = [], array $extra = []): array
    {
        $records = $this->run($statement, $parameters);
        $nodes = [];
        $edges = [];
        foreach ($records as $record) {
            $graph = $record['_graph'] ?? [];
            $nodes = array_merge($nodes, $graph['nodes'] ?? []);
            $edges = array_merge($edges, $graph['relationships'] ?? []);
        }
        if ($nodes === [] && isset($records[0])) {
            $nodes = $records[0]['nodes'] ?? [];
            $edges = $records[0]['edges'] ?? [];
        }

        $idMap = [];
        foreach ($nodes as $node) {
            if (is_array($node) && isset($node['id'], $node['elementId'])) {
                $idMap[(string) $node['id']] = (string) $node['elementId'];
            }
        }

        return array_merge([
            'ok' => true,
            'nodes' => $this->normalizeNodes($nodes),
            'edges' => $this->normalizeEdges($edges, $idMap),
            'warnings' => [],
        ], $extra);
    }

    private function run(string $statement, array $parameters = [], bool $includeGraph = true): array
    {
        $response = Http::timeout(20)
            ->withBasicAuth($this->user, $this->password)
            ->post($this->endpoint, [
                'statements' => [[
                    'statement' => $statement,
                    'parameters' => $parameters,
                    'resultDataContents' => $includeGraph ? ['row', 'graph'] : ['row'],
                ]],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Neo4j HTTP error ' . $response->status() . ': ' . $response->body());
        }

        $payload = $response->json() ?: [];
        $errors = $payload['errors'] ?? [];
        if (!empty($errors)) {
            throw new \RuntimeException($errors[0]['message'] ?? 'Neo4j query failed.');
        }

        $data = $payload['results'][0]['data'] ?? [];
        $columns = $payload['results'][0]['columns'] ?? [];

        return array_map(static function (array $record) use ($columns): array {
            $row = $record['row'] ?? [];
            $out = [];
            foreach ($columns as $index => $column) {
                $out[$column] = $row[$index] ?? null;
            }
            $out['_graph'] = $record['graph'] ?? [];
            $out['_meta'] = $record['meta'] ?? [];
            return $out;
        }, $data);
    }

    private function normalizeNodes(array $nodes): array
    {
        $normalized = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
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

    private function normalizeEdges(array $edges, array $idMap = []): array
    {
        $normalized = [];
        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                continue;
            }
            $id = (string) ($edge['elementId'] ?? $edge['id'] ?? '');
            $sourceRaw = (string) ($edge['startNodeElementId'] ?? $edge['startNode'] ?? $edge['start'] ?? '');
            $targetRaw = (string) ($edge['endNodeElementId'] ?? $edge['endNode'] ?? $edge['end'] ?? '');
            $source = $idMap[$sourceRaw] ?? $sourceRaw;
            $target = $idMap[$targetRaw] ?? $targetRaw;
            if ($id === '' || $source === '' || $target === '') {
                continue;
            }
            $properties = is_array($edge['properties'] ?? null) ? $edge['properties'] : [];
            $label = (string) ($properties['type'] ?? $properties['keywords'] ?? $properties['description'] ?? $edge['type'] ?? 'REL');
            $normalized[$id] = [
                'id' => $id,
                'source' => $source,
                'target' => $target,
                'type' => $label,
                'properties' => $properties,
                'weight' => max(1, count($this->stringList($properties['doc_ids'] ?? $properties['doc_id'] ?? []))),
            ];
        }

        return array_values($normalized);
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

    private function fulltextIndexName(): ?string
    {
        $records = $this->run('SHOW INDEXES YIELD name, type, entityType, labelsOrTypes, properties RETURN name, type, entityType, labelsOrTypes, properties', [], false);
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
            $this->run('SHOW INDEXES YIELD name, type, entityType, labelsOrTypes, properties RETURN name, type, entityType, labelsOrTypes, properties', [], false),
            static fn($record) => ($record['type'] ?? null) === 'VECTOR' && ($record['entityType'] ?? null) === 'NODE'
        ));
    }

    private function fulltextQuery(string $query): string
    {
        $terms = preg_split('/\s+/', trim($query)) ?: [];
        $terms = array_values(array_filter(array_map(
            static fn($term) => preg_replace('/[^[:alnum:]_\-]+/u', '', $term),
            $terms
        )));

        return $terms === [] ? $query : implode(' AND ', array_map(static fn($term) => $term . '*', $terms));
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
