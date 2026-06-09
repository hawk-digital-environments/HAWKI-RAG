<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Document;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\EventHandlers\IngestionEventHandler;
use App\Services\Pipeline\Events\PipelineEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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

        $this->get('/datasets')
            ->assertOk()
            ->assertSee('Datasets');

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
        config()->set('communication.rabbitmq.pipeline_events.enabled', false);

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

    public function test_ingestion_records_documents_inside_dataset_and_uses_dataset_targets(): void
    {
        config()->set('communication.rabbitmq.pipeline_events.enabled', false);

        Dataset::query()->create([
            'dataset_id' => 'dataset-ingest',
            'name' => 'Dataset Ingest',
            'description' => null,
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_dataset_ingest',
            'neo4j_namespace' => 'hawki_dataset_ingest',
            'created_at' => now(),
        ]);
        PipelineTask::query()->create([
            'task_id' => 'task-dataset-ingest',
            'dataset_id' => 'dataset-ingest',
            'status' => PipelineTask::STATUS_RUNNING,
            'started_at' => now(),
            'counters' => [],
            'metadata' => [],
        ]);

        Http::fake([
            '*/ingest' => Http::response(['ok' => true, 'documents' => 1], 200),
        ]);

        $root = storage_path('framework/testing/datasets/ingest');
        $markdownPath = "{$root}/page.md";
        File::ensureDirectoryExists($root);
        File::put($markdownPath, "# Dataset page\n\nDataset-scoped content.");

        app(IngestionEventHandler::class)->handle(PipelineEvent::normalize(PipelineEvent::PAGE_SCRAPED, [
            'task_id' => 'task-dataset-ingest',
            'job_id' => 'scrape-dataset-ingest',
            'dataset_id' => 'dataset-ingest',
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => 'https://example.test/dataset',
            'local_path' => $markdownPath,
            'content_hash' => hash_file('sha256', $markdownPath),
            'status' => PipelineJob::STATUS_COMPLETED,
        ]));

        Http::assertSent(fn ($request) => $request->url() === 'http://hawki_rag_bridge:8000/ingest'
            && $request['collection'] === 'hawki_dataset_ingest'
            && $request['neo4j_namespace'] === 'hawki_dataset_ingest'
            && $request['docs'][0]['payload']['dataset_id'] === 'dataset-ingest'
            && $request['docs'][0]['payload']['qdrant_collection'] === 'hawki_dataset_ingest'
            && $request['docs'][0]['payload']['neo4j_namespace'] === 'hawki_dataset_ingest');

        $this->assertDatabaseHas('documents', [
            'dataset_id' => 'dataset-ingest',
            'collection' => 'hawki_dataset_ingest',
            'checksum_sha256' => hash_file('sha256', $markdownPath),
            'status' => Document::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('pipeline_jobs', [
            'task_id' => 'task-dataset-ingest',
            'job_type' => PipelineJob::TYPE_INGEST,
            'status' => PipelineJob::STATUS_COMPLETED,
        ]);
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
