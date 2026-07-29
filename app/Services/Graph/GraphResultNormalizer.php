<?php

declare(strict_types=1);

namespace App\Services\Graph;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class GraphResultNormalizer
{
    public function __construct(private GraphSourceDocumentResolver $sources)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function records(array $payload): array
    {
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
        }, is_array($data) ? $data : []);
    }

    /**
     * @return array<string, mixed>
     */
    public function graph(array $records, array $extra = []): array
    {
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

        $normalizedNodes = $this->sources->attachToNodes($this->nodes($nodes));

        return array_merge([
            'ok' => true,
            'nodes' => $normalizedNodes,
            'edges' => $this->edges($edges, $idMap),
            'warnings' => [],
        ], $extra);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function nodeSearchResults(array $records): array
    {
        return array_values(array_filter(array_map(function (array $record): ?array {
            $graphNodes = $record['_graph']['nodes'] ?? [];
            $node = $this->sources->attachToNodes($this->nodes($graphNodes))[0] ?? null;
            if ($node) {
                $node['score'] = $record['score'] ?? null;
                $node['highlighted'] = true;
            }

            return $node;
        }, $records)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function nodes(array $nodes): array
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

    /**
     * @return list<array<string, mixed>>
     */
    public function edges(array $edges, array $idMap = []): array
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

    /**
     * @return list<string>
     */
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
