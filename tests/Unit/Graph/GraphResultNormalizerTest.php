<?php

declare(strict_types=1);

namespace Tests\Unit\Graph;

use App\Services\Graph\GraphResultNormalizer;
use Tests\TestCase;

class GraphResultNormalizerTest extends TestCase
{
    public function test_it_normalizes_records_nodes_edges_and_removes_vectors(): void
    {
        $normalizer = app(GraphResultNormalizer::class);

        $records = $normalizer->records([
            'results' => [[
                'columns' => ['name', 'score'],
                'data' => [[
                    'row' => ['Alice', 0.94],
                    'graph' => [
                        'nodes' => [
                            [
                                'id' => 1,
                                'elementId' => 'node-1',
                                'labels' => ['Person'],
                                'properties' => ['name' => 'Alice', 'embedding' => [1, 2, 3], 'doc_id' => 'doc-1'],
                            ],
                            [
                                'id' => 2,
                                'elementId' => 'node-2',
                                'labels' => ['Topic'],
                                'properties' => ['entity_id' => 'RAWKI', 'vector' => [4, 5, 6], 'doc_ids' => ['doc-2']],
                            ],
                        ],
                        'relationships' => [[
                            'id' => 10,
                            'elementId' => 'rel-1',
                            'startNode' => 1,
                            'endNode' => 2,
                            'type' => 'MENTIONS',
                            'properties' => ['doc_id' => 'doc-1'],
                        ]],
                    ],
                    'meta' => [['type' => 'node']],
                ]],
            ]],
        ]);

        $graph = $normalizer->graph($records, ['mode' => 'unit']);

        $this->assertSame('Alice', $records[0]['name']);
        $this->assertSame(0.94, $records[0]['score']);
        $this->assertSame('unit', $graph['mode']);
        $this->assertSame('node-1', $graph['nodes'][0]['id']);
        $this->assertSame('Alice', $graph['nodes'][0]['label']);
        $this->assertArrayNotHasKey('embedding', $graph['nodes'][0]['properties']);
        $this->assertArrayNotHasKey('vector', $graph['nodes'][1]['properties']);
        $this->assertSame('node-1', $graph['edges'][0]['source']);
        $this->assertSame('node-2', $graph['edges'][0]['target']);
        $this->assertSame(1, $graph['edges'][0]['weight']);
    }
}
