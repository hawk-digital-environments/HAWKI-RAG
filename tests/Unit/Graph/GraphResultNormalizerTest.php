<?php

declare(strict_types=1);

namespace Tests\Unit\Graph;

use App\Models\Document;
use App\Services\Graph\GraphResultNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GraphResultNormalizerTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_source_snippets_remain_valid_utf8_when_the_window_starts_near_a_multibyte_character(): void
    {
        $path = storage_path('app/graph-source-utf8.md');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'ö'.str_repeat('a', 179).'Veröffentlicht'.str_repeat('z', 430));

        try {
            Document::query()->create([
                'external_id' => 'doc-utf8',
                'collection' => 'graph-utf8',
                'source_type' => Document::SOURCE_UPLOAD,
                'storage_path' => $path,
                'checksum_sha256' => hash('sha256', 'doc-utf8'),
            ]);

            $graph = app(GraphResultNormalizer::class)->graph([[
                '_graph' => [
                    'nodes' => [[
                        'elementId' => 'node-utf8',
                        'labels' => ['Entity'],
                        'properties' => [
                            'name' => 'Veröffentlicht',
                            'doc_id' => 'doc-utf8',
                        ],
                    ]],
                    'relationships' => [],
                ],
            ]]);

            $snippet = $graph['nodes'][0]['source_documents'][0]['markdownSnippet'];

            $this->assertIsString($snippet);
            $this->assertTrue(mb_check_encoding($snippet, 'UTF-8'));
            $this->assertStringContainsString('ö', $snippet);
            $this->assertIsString(json_encode($graph, JSON_THROW_ON_ERROR));
        } finally {
            File::delete($path);
        }
    }
}
