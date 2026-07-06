<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Document;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DatasetManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_datasets_are_visible_with_counts_last_ingestion_and_graph_stats(): void
    {
        $this->withoutVite();
        $this->fakeGraphStats(points: 7, nodes: 3, relationships: 2);

        Dataset::query()->create([
            'dataset_id' => 'dataset-ui',
            'name' => 'Dataset UI',
            'description' => 'Demo dataset',
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_dataset_ui',
            'neo4j_namespace' => 'hawki_dataset_ui',
            'created_at' => now()->subHour(),
        ]);

        Document::query()->create([
            'dataset_id' => 'dataset-ui',
            'collection' => 'hawki_dataset_ui',
            'source_type' => Document::SOURCE_SCRAPE,
            'source_url' => 'https://example.test/page',
            'storage_path' => '/app/shared/dataset-ui/page.md',
            'checksum_sha256' => hash('sha256', 'dataset-ui-doc'),
            'status' => Document::STATUS_COMPLETED,
        ]);

        PipelineTask::query()->create([
            'task_id' => 'task-dataset-ui',
            'dataset_id' => 'dataset-ui',
            'status' => PipelineTask::STATUS_COMPLETED,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(5),
            'counters' => ['jobs_total' => 1, 'ingested' => 1],
            'metadata' => [],
        ]);

        PipelineJob::query()->create([
            'job_id' => 'ingest-dataset-ui',
            'task_id' => 'task-dataset-ui',
            'job_type' => PipelineJob::TYPE_INGEST,
            'source_url' => 'https://example.test/page',
            'local_path' => '/app/shared/dataset-ui/page.md',
            'status' => PipelineJob::STATUS_COMPLETED,
            'started_at' => now()->subMinutes(7),
            'finished_at' => now()->subMinutes(6),
            'metadata' => [],
        ]);

        Dataset::query()->create([
            'dataset_id' => 'orphan-dataset',
            'name' => 'Orphan Dataset',
            'description' => 'Should not appear without a task',
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_orphan_dataset',
            'neo4j_namespace' => 'hawki_orphan_dataset',
            'created_at' => now(),
        ]);

        $this->get('/heaps')
            ->assertOk()
            ->assertSee('HAWKI Heap Browser')
            ->assertSee('data-datasets-dashboard', false);

        $this->actingAsApiUser();

        $this->getJson('/api/datasets')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'datasetId' => 'dataset-ui',
                'documentCount' => 1,
                'taskCount' => 1,
            ])
            ->assertJsonMissing([
                'datasetId' => 'orphan-dataset',
            ]);

        $this->getJson('/api/datasets/dataset-ui')
            ->assertOk()
            ->assertJsonPath('dataset.datasetId', 'dataset-ui')
            ->assertJsonPath('dataset.tasks.0.taskId', 'task-dataset-ui')
            ->assertJsonPath('dataset.documents.0.datasetId', 'dataset-ui')
            ->assertJsonPath('dataset.ingestionHistory.0.jobId', 'ingest-dataset-ui');
    }

    public function test_starting_pipeline_task_creates_and_uses_dataset(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/pipeline/tasks/start', [
            'task_id' => 'task-dataset-start',
            'dataset_id' => 'dataset-start',
            'urls' => ['https://example.test/start'],
        ])
            ->assertCreated()
            ->assertJsonPath('task.datasetId', 'dataset-start');

        $this->assertDatabaseHas('datasets', [
            'dataset_id' => 'dataset-start',
            'qdrant_collection' => 'hawki_dataset_start',
            'neo4j_namespace' => 'hawki_dataset_start',
        ]);
        $this->assertDatabaseHas('pipeline_tasks', [
            'task_id' => 'task-dataset-start',
            'dataset_id' => 'dataset-start',
        ]);

        $job = PipelineJob::query()
            ->where('task_id', 'task-dataset-start')
            ->firstOrFail();
        $this->assertSame('dataset-start', $job->task->dataset_id);
        $this->assertSame('hawki_dataset_start', $job->metadata['dataset']['qdrant_collection'] ?? null);
        $this->assertSame('hawki_dataset_start', $job->metadata['dataset']['neo4j_namespace'] ?? null);
    }

    public function test_missing_qdrant_collection_is_reported_as_empty_dataset_stats(): void
    {
        config()->set('config.qdrant_http_url', 'http://qdrant.test');
        config()->set('config.neo4j_http_url', 'http://neo4j.test');
        config()->set('config.neo4j_user', 'neo4j');
        config()->set('config.neo4j_password', 'secret');

        Http::fake([
            'http://qdrant.test/*/points/count' => Http::response([
                'status' => ['error' => 'Not found'],
            ], 404),
            'http://neo4j.test/*' => Http::response([
                'results' => [[
                    'columns' => ['nodes', 'relationships'],
                    'data' => [[
                        'row' => [0, 0],
                    ]],
                ]],
                'errors' => [],
            ], 200),
        ]);

        Dataset::query()->create([
            'dataset_id' => 'empty-dataset',
            'name' => 'Empty Dataset',
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_empty_dataset',
            'neo4j_namespace' => 'hawki_empty_dataset',
        ]);

        PipelineTask::query()->create([
            'task_id' => 'task-empty-dataset',
            'dataset_id' => 'empty-dataset',
            'status' => PipelineTask::STATUS_PENDING,
            'metadata' => [],
        ]);

        $this->actingAsApiUser();

        $this->getJson('/api/datasets/empty-dataset')
            ->assertOk()
            ->assertJsonPath('dataset.graphStats.qdrant.ok', true)
            ->assertJsonPath('dataset.graphStats.qdrant.points', 0)
            ->assertJsonPath('dataset.graphStats.qdrant.status', 'not_created')
            ->assertJsonPath('dataset.graphStats.qdrant.message', 'Collection not created yet');
    }

    public function test_deleting_dataset_removes_neo4j_nodes_by_single_doc_id_and_attached_relationships(): void
    {
        config()->set('config.qdrant_http_url', 'http://qdrant.test');
        config()->set('config.neo4j_http_url', 'http://neo4j.test');
        config()->set('config.neo4j_user', 'neo4j');
        config()->set('config.neo4j_password', 'secret');

        Http::fake([
            'http://qdrant.test/collections/hawki_delete_dataset' => Http::response([], 200),
            'http://neo4j.test/*' => Http::response([
                'results' => [
                    ['data' => [['row' => [2]]]],
                    ['data' => [['row' => [3, 4]]]],
                    ['data' => [['row' => [1]]]],
                ],
                'errors' => [],
            ], 200),
        ]);

        Dataset::query()->create([
            'dataset_id' => 'delete-dataset',
            'name' => 'Delete Dataset',
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_delete_dataset',
            'neo4j_namespace' => 'hawki_delete_dataset',
        ]);

        Document::query()->create([
            'external_id' => 'ingest-delete-dataset',
            'dataset_id' => 'delete-dataset',
            'collection' => 'hawki_delete_dataset',
            'source_type' => Document::SOURCE_UPLOAD,
            'source_url' => 'file:///delete-dataset.pdf',
            'storage_path' => '/app/shared/delete-dataset/content.md',
            'checksum_sha256' => hash('sha256', 'delete-dataset-doc'),
            'status' => Document::STATUS_COMPLETED,
        ]);

        $this->actingAsApiUser();

        $this->deleteJson('/api/datasets/delete-dataset/storage')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('cleanup.neo4j.documentJobIds', 1)
            ->assertJsonPath('cleanup.neo4j.nodes', 4)
            ->assertJsonPath('cleanup.neo4j.relationships', 6);

        $this->assertDatabaseMissing('datasets', ['dataset_id' => 'delete-dataset']);

        Http::assertSent(function (Request $request): bool {
            if (! str_starts_with($request->url(), 'http://neo4j.test/')) {
                return false;
            }

            $statements = $request['statements'];
            $nodeCleanup = (string) ($statements[1]['statement'] ?? '');

            return ($statements[1]['parameters']['document_job_ids'] ?? []) === ['ingest-delete-dataset']
                && str_contains($nodeCleanup, 'OR n.doc_id IN $document_job_ids')
                && str_contains($nodeCleanup, 'OPTIONAL MATCH (node)-[attached]-()')
                && str_contains($nodeCleanup, 'FOREACH (node IN nodes | DELETE node)');
        });
    }

    private function fakeGraphStats(int $points, int $nodes, int $relationships): void
    {
        config()->set('config.qdrant_http_url', 'http://qdrant.test');
        config()->set('config.neo4j_http_url', 'http://neo4j.test');
        config()->set('config.neo4j_user', 'neo4j');
        config()->set('config.neo4j_password', 'secret');

        Http::fake([
            'http://qdrant.test/*/points/count' => Http::response([
                'result' => ['count' => $points],
            ], 200),
            'http://neo4j.test/*' => Http::response([
                'results' => [[
                    'columns' => ['nodes', 'relationships'],
                    'data' => [[
                        'row' => [$nodes, $relationships],
                    ]],
                ]],
                'errors' => [],
            ], 200),
        ]);
    }
}
