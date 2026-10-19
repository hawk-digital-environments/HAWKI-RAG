<?php

namespace Tests\Feature\Datasets;

use App\Models\Dataset;
use App\Models\ManagedDocument;
use App\Models\ManagedDocumentOutput;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Dataset\DatasetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DatasetManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $settingsPath = storage_path('framework/testing/dataset-management-settings.json');
        File::delete($settingsPath);
        config()->set('config.admin_settings_path', $settingsPath);
        config()->set('temporal.ingestion.provider', 'ollama');
        config()->set('config.graph_provider', 'ollama');
    }

    public function test_dataset_creation_is_retry_safe_for_compatible_metadata(): void
    {
        $this->actingAsApiUser();
        $this->fakeGraphStats(points: 0, nodes: 0, relationships: 0);
        $payload = [
            'dataset_id' => 'assistant_42',
            'name' => 'Assistant 42',
            'description' => 'Knowledge for Assistant 42',
        ];

        $this->postJson('/api/datasets', $payload)
            ->assertCreated()
            ->assertJsonPath('dataset_id', 'assistant_42')
            ->assertJsonPath('dataset.qdrant_collection', 'hawki_assistant_42');

        $this->postJson('/api/datasets', $payload)
            ->assertOk()
            ->assertJsonPath('dataset_id', 'assistant_42');

        $this->assertSame(
            1,
            Dataset::query()->where('dataset_id', 'assistant_42')->count(),
        );
    }

    public function test_dataset_creation_rejects_conflicting_metadata(): void
    {
        $this->actingAsApiUser();
        $this->fakeGraphStats(points: 0, nodes: 0, relationships: 0);

        $this->postJson('/api/datasets', [
            'dataset_id' => 'assistant_42',
            'name' => 'Assistant 42',
        ])->assertCreated();

        $this->postJson('/api/datasets', [
            'dataset_id' => 'assistant_42',
            'name' => 'Different assistant',
        ])
            ->assertConflict()
            ->assertJsonPath('error', 'dataset_creation_conflict');

        $this->assertDatabaseHas('datasets', [
            'dataset_id' => 'assistant_42',
            'name' => 'Assistant 42',
        ]);
    }

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

        ManagedDocument::query()->create([
            'document_id' => 'adoc_dataset_ui_1',
            'dataset_id' => 'dataset-ui',
            'display_name' => 'page.md',
            'source_type' => 'scrape',
            'source_url' => 'https://example.test/page',
            'source_checksum_sha256' => hash('sha256', 'dataset-ui-doc'),
            'graph_enabled' => true,
            'status' => ManagedDocument::STATUS_INDEXED,
        ]);

        ManagedDocumentOutput::query()->create([
            'document_id' => 'adoc_dataset_ui_1',
            'bridge_document_id' => 'doc-dataset-ui-1',
            'qdrant_collection' => 'hawki_dataset_ui',
            'neo4j_namespace' => 'hawki_dataset_ui',
            'chunk_count' => 7,
            'status' => 'indexed',
            'active' => true,
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
            ->assertSee('HAWKI Data Browser')
            ->assertSee('data-datasets-dashboard', false);

        $this->actingAsApiUser();

        $this->getJson('/api/datasets')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'dataset_id' => 'dataset-ui',
                'document_count' => 1,
                'task_count' => 1,
            ])
            ->assertJsonMissing([
                'dataset_id' => 'orphan-dataset',
            ]);

        $this->getJson('/api/datasets/dataset-ui')
            ->assertOk()
            ->assertJsonPath('dataset.dataset_id', 'dataset-ui')
            ->assertJsonPath('dataset.embedding_provider', 'ollama')
            ->assertJsonPath('dataset.embedding_model', 'bge-m3')
            ->assertJsonPath('dataset.tasks.0.task_id', 'task-dataset-ui')
            ->assertJsonMissingPath('dataset.documents')
            ->assertJsonPath('dataset.ingestion_history.0.job_id', 'ingest-dataset-ui');
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
            ->assertJsonPath('task.dataset_id', 'dataset-start');

        $this->assertDatabaseHas('datasets', [
            'dataset_id' => 'dataset-start',
            'qdrant_collection' => 'hawki_dataset_start',
            'neo4j_namespace' => 'hawki_dataset_start',
            'embedding_provider' => 'ollama',
            'embedding_model' => 'bge-m3',
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
        $this->assertSame('ollama', $job->metadata['dataset']['embedding_provider'] ?? null);
        $this->assertSame('bge-m3', $job->metadata['dataset']['embedding_model'] ?? null);
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
            ->assertJsonPath('dataset.graph_stats.qdrant.ok', true)
            ->assertJsonPath('dataset.graph_stats.qdrant.points', 0)
            ->assertJsonPath('dataset.graph_stats.qdrant.status', 'not_created')
            ->assertJsonPath('dataset.graph_stats.qdrant.message', 'Collection not created yet');
    }

    public function test_dataset_deletion_retains_ambiguous_legacy_output_evidence(): void
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

        ManagedDocument::query()->create([
            'document_id' => 'adoc_delete_dataset_1',
            'dataset_id' => 'delete-dataset',
            'display_name' => 'delete-dataset.pdf',
            'source_type' => 'upload',
            'source_url' => 'file:///delete-dataset.pdf',
            'source_checksum_sha256' => hash('sha256', 'delete-dataset-doc'),
            'graph_enabled' => true,
            'status' => ManagedDocument::STATUS_INDEXED,
        ]);

        ManagedDocumentOutput::query()->create([
            'document_id' => 'adoc_delete_dataset_1',
            'bridge_document_id' => 'ingest-delete-dataset',
            'qdrant_collection' => 'hawki_delete_dataset',
            'neo4j_namespace' => 'hawki_delete_dataset',
            'chunk_count' => 1,
            'status' => 'indexed',
            'active' => true,
        ]);

        $this->actingAsApiUser();

        $this->deleteJson('/api/datasets/delete-dataset/storage')
            ->assertAccepted()
            ->assertJsonPath('success', true);
        $this->assertSame(409, app(DatasetService::class)->cleanup('delete-dataset')['status']);
        $this->assertDatabaseHas('datasets', ['dataset_id' => 'delete-dataset', 'status' => Dataset::STATUS_ARCHIVED]);
        $this->assertDatabaseHas('managed_document_outputs', ['active' => true]);
        Http::assertNothingSent();
    }

    public function test_dataset_final_verification_preserves_shared_storage_and_requires_zero_owned_data(): void
    {
        $dataset = Dataset::query()->create(['dataset_id' => 'scoped-delete', 'name' => 'Scoped delete',
            'qdrant_collection' => 'shared_collection', 'neo4j_namespace' => 'owned_namespace']);
        config()->set('config.qdrant_http_url', 'http://qdrant.test');
        config()->set('config.neo4j_http_url', 'http://neo4j.test');
        $remaining = 1;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$remaining) {
            if (str_starts_with($request->url(), 'http://qdrant.test')) {
                $this->assertSame('POST', $request->method());
                $this->assertSame(['must' => [['key' => 'dataset_id', 'match' => ['value' => 'scoped-delete']]]], $request['filter']);
                $this->assertTrue($request['exact']);

                return Http::response(['result' => ['count' => $remaining]]);
            }
            foreach ($request['statements'] as $statement) {
                $this->assertStringContainsString('dataset_id = $dataset_id AND', $statement['statement']);
                $this->assertStringNotContainsString(' OR ', $statement['statement']);
                $this->assertStringNotContainsString('DELETE', $statement['statement']);
                $this->assertSame(['dataset_id' => 'scoped-delete', 'namespace' => 'owned_namespace'], $statement['parameters']);
            }

            return Http::response(['results' => [['data' => [['row' => [0]]]], ['data' => [['row' => [0]]]]], 'errors' => []]);
        });
        $this->deleteJson('/api/datasets/scoped-delete/storage')->assertAccepted();
        Http::assertNothingSent();
        $this->artisan('schedule:run')->assertExitCode(0);
        $this->assertDatabaseHas('datasets', ['dataset_id' => $dataset->dataset_id, 'status' => Dataset::STATUS_ARCHIVED]);
        $remaining = 0;
        $this->artisan('schedule:run')->assertExitCode(0);
        $this->deleteJson('/api/datasets/scoped-delete/storage')->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('datasets', ['dataset_id' => $dataset->dataset_id, 'status' => Dataset::STATUS_DELETED]);
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
