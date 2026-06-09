<?php

declare(strict_types=1);

namespace App\Services\Graph;

class Neo4jGraphExplorer
{
    public function __construct(
        private readonly Neo4jClient $neo4j,
        private readonly GraphSearchService $search,
        private readonly GraphSnapshotStore $snapshots,
    ) {}

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
        return $this->search->searchEntities($query, $limit);
    }

    public function semanticSearch(string $query, int $limit = 8): array
    {
        return $this->search->semanticSearch($query, $limit);
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

    private function normalizeEdges(array $edges, array $idMap = []): array
    {
        $normalized = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
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
