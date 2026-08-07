<?php

declare(strict_types=1);

namespace Tests\Unit\Graph;

use App\Models\ManagedDocument;
use App\Models\ManagedDocumentOutput;
use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Models\PipelineTask;
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
            $task = PipelineTask::query()->create([
                'task_id' => 'task-graph-utf8',
                'dataset_id' => 'graph-utf8',
                'status' => PipelineTask::STATUS_COMPLETED,
                'metadata' => [],
            ]);
            $job = PipelineJob::query()->create([
                'job_id' => 'job-graph-utf8',
                'task_id' => $task->task_id,
                'job_type' => PipelineJob::TYPE_INGEST,
                'status' => PipelineJob::STATUS_COMPLETED,
                'metadata' => [],
            ]);
            ManagedDocument::query()->create([
                'document_id' => 'adoc_graph_utf8_1',
                'dataset_id' => 'graph-utf8',
                'display_name' => 'graph-source-utf8.md',
                'source_type' => 'upload',
                'source_checksum_sha256' => hash('sha256', 'doc-utf8'),
                'graph_enabled' => true,
                'status' => ManagedDocument::STATUS_INDEXED,
                'latest_task_id' => $task->task_id,
                'latest_job_id' => $job->job_id,
            ]);
            ManagedDocumentOutput::query()->create([
                'document_id' => 'adoc_graph_utf8_1',
                'bridge_document_id' => 'doc-utf8',
                'qdrant_collection' => 'graph-utf8',
                'neo4j_namespace' => 'graph-utf8',
                'chunk_count' => 1,
                'status' => 'indexed',
                'active' => true,
            ]);
            PipelineStageState::query()->create([
                'pipeline_job_id' => $job->id,
                'job_id' => $job->job_id,
                'stage' => 'convert',
                'status' => PipelineJob::STATUS_COMPLETED,
                'counts' => [],
                'metadata' => [
                    'artifacts' => [[
                        'uri' => $path,
                        'media_type' => 'text/markdown',
                        'size_bytes' => filesize($path),
                    ]],
                ],
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
