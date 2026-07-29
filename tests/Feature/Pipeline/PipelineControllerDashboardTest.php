<?php

namespace Tests\Feature\Pipeline;

use App\Models\Dataset;
use App\Models\Document;
use App\Models\IngestionSource;
use App\Models\ManagedDocument;
use App\Models\ManagedDocumentOutput;
use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Models\PipelineTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PipelineControllerDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsApiUser();
    }

    public function test_pipeline_controller_has_its_own_page_and_is_removed_from_playground(): void
    {
        $this->withoutVite();

        $this->get('/pipeline-controller')
            ->assertOk()
            ->assertSee('Pipeline Controller')
            ->assertSee('data-pipeline-controller-dashboard', false)
            ->assertSee('pipeline-controller-config', false)
            ->assertDontSee('pipeline-task-select', false);

        $this->get('/hawki-rag-playground')
            ->assertOk()
            ->assertDontSee('Scraper Pipeline')
            ->assertDontSee('pipeline-file-form', false)
            ->assertDontSee('pipeline-task-select', false);
    }

    public function test_uploading_file_starts_temporal_ingest_workflow(): void
    {
        $root = storage_path('framework/testing/pipeline-controller');
        File::deleteDirectory($root);
        config()->set('temporal.storage.shared_root', $root);
        config()->set('file_converter.raganything_supported_extensions', ['pdf']);
        Http::fake([
            '*temporal/workflows/ingest' => Http::response([
                'workflow_id' => 'ingest-source-upload-workflow',
                'run_id' => 'upload-run-1',
            ]),
        ]);

        $this->actingAsApiUser();

        $response = $this->post('/api/pipeline/controller/files', [
            'dataset_id' => 'controller-test',
            'graph' => 'false',
            'file' => UploadedFile::fake()->create('sample.pdf', 12, 'application/pdf'),
        ], [
            'Accept' => 'application/json',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('dataset_id', 'controller-test')
            ->assertJsonPath('task.stages.scrape.status', 'n/a')
            ->assertJsonPath('task.stages.convert.status', 'processing')
            ->assertJsonPath('task.stages.ingest.status', PipelineJob::STATUS_QUEUED);

        $taskId = $response->json('task_id');
        $jobId = $response->json('job_id');

        $this->assertDatabaseHas('datasets', [
            'dataset_id' => 'controller-test',
        ]);
        $this->assertDatabaseHas('pipeline_tasks', [
            'task_id' => $taskId,
            'dataset_id' => 'controller-test',
            'status' => PipelineTask::STATUS_RUNNING,
        ]);
        $this->assertDatabaseHas('pipeline_jobs', [
            'job_id' => $jobId,
            'task_id' => $taskId,
            'job_type' => PipelineJob::TYPE_INGEST,
            'source_url' => 'upload://sample.pdf',
            'status' => PipelineJob::STATUS_RUNNING,
            'current_stage' => 'temporal.workflow_started',
            'temporal_workflow_id' => 'ingest-source-upload-workflow',
            'temporal_run_id' => 'upload-run-1',
        ]);
        $this->assertDatabaseHas('ingestion_sources', [
            'source_url' => 'upload://sample.pdf',
            'task_id' => $taskId,
            'dataset_id' => 'controller-test',
            'index_status' => 'running',
            'temporal_workflow_id' => 'ingest-source-upload-workflow',
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === config('config.hawki_rag_bridge_url').'/temporal/workflows/ingest'
            && data_get($request->data(), 'workflow_input.upload.original_filename') === 'sample.pdf'
            && data_get($request->data(), 'workflow_input.upload.local_path') !== null
            && data_get($request->data(), 'workflow_input.converter_mode') === 'native'
            && data_get($request->data(), 'workflow_input.ingestion.graph') === false);

        File::deleteDirectory($root);
    }

    public function test_scraper_task_detail_includes_temporal_stage_rows(): void
    {
        $task = PipelineTask::query()->create([
            'task_id' => 'task-scraper-stage-detail',
            'dataset_id' => 'lubeck',
            'status' => PipelineTask::STATUS_COMPLETED,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'counters' => ['jobs_total' => 1],
            'metadata' => [
                'request' => [
                    'metadata' => [
                        'source' => 'scraper-task-ui',
                        'max_pages' => 300,
                    ],
                ],
            ],
        ]);
        $job = PipelineJob::query()->create([
            'job_id' => 'ingest-scraper-stage-detail',
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_INGEST,
            'status' => PipelineJob::STATUS_COMPLETED,
            'source_url' => 'https://uni-luebeck.de',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        foreach ([
            ['stage' => 'scrape', 'counts' => ['total' => 4, 'processed' => 4]],
            ['stage' => 'convert', 'counts' => ['total' => 21, 'processed' => 21]],
            ['stage' => 'ingest', 'counts' => ['total' => 21, 'processed' => 21]],
        ] as $stage) {
            PipelineStageState::query()->create([
                'pipeline_job_id' => $job->id,
                'job_id' => $job->job_id,
                'stage' => $stage['stage'],
                'status' => PipelineJob::STATUS_COMPLETED,
                'counts' => $stage['counts'],
                'metadata' => [],
                'errors' => [],
                'warnings' => [],
            ]);
        }

        $this->getJson('/api/pipeline/tasks/task-scraper-stage-detail')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('task.counters.scraped', 4)
            ->assertJsonPath('task.counters.files_found', 21)
            ->assertJsonPath('task.counters.converted', 21)
            ->assertJsonPath('task.stages.scrape.status', PipelineJob::STATUS_FAILED)
            ->assertJsonPath('task.stages.scrape.counts.pages_crawled', 4)
            ->assertJsonPath('task.stages.scrape.counts.total_pages', 300)
            ->assertJsonPath('task.stages.scrape.counts.page_limit', 300)
            ->assertJsonPath('task.stages.scrape.errors.0', 'Scraper stopped at 4/300 pages before reaching the configured page limit.')
            ->assertJsonPath('task.stages.convert.status', PipelineJob::STATUS_COMPLETED)
            ->assertJsonPath('task.stages.convert.counts.converted_files', 21)
            ->assertJsonPath('task.stages.convert.counts.source_files', 21)
            ->assertJsonPath('task.stages.ingest.status', PipelineJob::STATUS_COMPLETED)
            ->assertJsonPath('task.stages.ingest.counts.completed', 21);
    }

    public function test_task_detail_resolves_managed_document_summaries_for_jobs_and_sources(): void
    {
        $task = PipelineTask::query()->create([
            'task_id' => 'task-managed-read',
            'dataset_id' => 'managed-read',
            'status' => PipelineTask::STATUS_RUNNING,
            'started_at' => now()->subMinute(),
            'counters' => ['jobs_total' => 1],
            'metadata' => [],
        ]);

        $job = PipelineJob::query()->create([
            'job_id' => 'job-managed-read',
            'task_id' => $task->task_id,
            'source_id' => 'source-managed-read',
            'job_type' => PipelineJob::TYPE_INGEST,
            'status' => PipelineJob::STATUS_RUNNING,
            'source_url' => 'upload://managed-read.pdf',
            'started_at' => now()->subMinute(),
            'metadata' => [],
        ]);

        IngestionSource::query()->create([
            'source_id' => 'source-managed-read',
            'source_url' => 'upload://managed-read.pdf',
            'task_id' => $task->task_id,
            'dataset_id' => 'managed-read',
            'content_hash' => hash('sha256', 'managed-read'),
            'document_version' => 'version-managed-read',
            'index_status' => IngestionSource::STATUS_READY,
            'raw_storage_path' => '/shared/sources/source-managed-read/raw/',
            'markdown_storage_path' => '/shared/sources/source-managed-read/markdown/',
            'metadata' => [],
            'ready_at' => now(),
        ]);

        ManagedDocument::query()->create([
            'document_id' => 'adoc_managed_read_1',
            'dataset_id' => 'managed-read',
            'display_name' => 'managed-read.pdf',
            'source_type' => 'upload',
            'source_url' => 'upload://managed-read.pdf',
            'graph_enabled' => true,
            'status' => ManagedDocument::STATUS_INDEXED,
            'latest_source_id' => 'source-managed-read',
            'latest_task_id' => $task->task_id,
            'latest_job_id' => $job->job_id,
            'indexed_at' => now(),
        ]);

        ManagedDocumentOutput::query()->create([
            'document_id' => 'adoc_managed_read_1',
            'bridge_document_id' => 'doc-managed-read-1',
            'qdrant_collection' => 'hawki_managed_read',
            'neo4j_namespace' => 'hawki_managed_read',
            'chunk_count' => 8,
            'status' => 'indexed',
            'active' => true,
            'indexed_at' => now(),
        ]);

        Document::query()->create([
            'id' => (string) Str::uuid(),
            'external_id' => 'doc-managed-read-1',
            'dataset_id' => 'managed-read',
            'collection' => 'hawki_managed_read',
            'source_type' => 'upload',
            'source_url' => 'upload://managed-read.pdf',
            'original_filename' => 'managed-read.pdf',
            'storage_path' => '/tmp/managed-read/managed-read.md',
            'mime_type' => 'text/markdown',
            'file_size' => 123,
            'checksum_sha256' => hash('sha256', 'managed-read'),
            'title' => 'managed-read',
            'metadata_json' => [
                'source_id' => 'source-managed-read',
                'task_id' => $task->task_id,
                'job_id' => $job->job_id,
                'document_id' => 'doc-managed-read-1',
                'qdrant_collection' => 'hawki_managed_read',
                'neo4j_namespace' => 'hawki_managed_read',
            ],
            'status' => Document::STATUS_COMPLETED,
        ]);

        $this->getJson("/api/pipeline/tasks/{$task->task_id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('task.managed_documents.0.document_id', 'adoc_managed_read_1')
            ->assertJsonPath('task.managed_document_count', 1)
            ->assertJsonPath('task.jobs.0.managed_documents.0.document_id', 'adoc_managed_read_1')
            ->assertJsonPath('task.jobs.0.source.source_id', 'source-managed-read')
            ->assertJsonPath('task.jobs.0.source.managed_documents.0.document_id', 'adoc_managed_read_1')
            ->assertJsonPath('task.sources.0.source_id', 'source-managed-read')
            ->assertJsonPath('task.sources.0.managed_documents.0.document_id', 'adoc_managed_read_1');
    }

    public function test_job_status_resolves_managed_document_summaries_for_source_view(): void
    {
        $task = PipelineTask::query()->create([
            'task_id' => 'task-status-managed-read',
            'dataset_id' => 'managed-status-read',
            'status' => PipelineTask::STATUS_RUNNING,
            'started_at' => now()->subMinute(),
            'counters' => ['jobs_total' => 1],
            'metadata' => [],
        ]);

        $job = PipelineJob::query()->create([
            'job_id' => 'job-status-managed-read',
            'task_id' => $task->task_id,
            'source_id' => 'source-status-managed-read',
            'job_type' => PipelineJob::TYPE_INGEST,
            'status' => PipelineJob::STATUS_RUNNING,
            'current_stage' => 'ingest',
            'source_url' => 'upload://status-managed-read.pdf',
            'started_at' => now()->subMinute(),
            'metadata' => [],
        ]);

        IngestionSource::query()->create([
            'source_id' => 'source-status-managed-read',
            'source_url' => 'upload://status-managed-read.pdf',
            'task_id' => $task->task_id,
            'dataset_id' => 'managed-status-read',
            'content_hash' => hash('sha256', 'managed-status-read'),
            'document_version' => 'version-status-managed-read',
            'index_status' => IngestionSource::STATUS_RUNNING,
            'raw_storage_path' => '/shared/sources/source-status-managed-read/raw/',
            'markdown_storage_path' => '/shared/sources/source-status-managed-read/markdown/',
            'metadata' => [],
        ]);

        ManagedDocument::query()->create([
            'document_id' => 'adoc_status_managed_read_1',
            'dataset_id' => 'managed-status-read',
            'display_name' => 'status-managed-read.pdf',
            'source_type' => 'upload',
            'source_url' => 'upload://status-managed-read.pdf',
            'graph_enabled' => false,
            'status' => ManagedDocument::STATUS_PROCESSING,
            'latest_source_id' => 'source-status-managed-read',
            'latest_task_id' => $task->task_id,
            'latest_job_id' => $job->job_id,
        ]);

        $this->getJson("/api/pipeline/status/{$job->job_id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('managed_documents.0.document_id', 'adoc_status_managed_read_1')
            ->assertJsonPath('managed_document_count', 1)
            ->assertJsonPath('source.source_id', 'source-status-managed-read')
            ->assertJsonPath('source.managed_documents.0.document_id', 'adoc_status_managed_read_1')
            ->assertJsonPath('tracked.managed_documents.0.document_id', 'adoc_status_managed_read_1');
    }

    public function test_native_upload_rejects_non_raganything_file_type(): void
    {
        config()->set('file_converter.raganything_supported_extensions', ['pdf']);

        $this->actingAsApiUser();

        $this->post('/api/pipeline/controller/files', [
            'dataset_id' => 'native-reject',
            'file' => UploadedFile::fake()->create('diagram.svg', 12, 'image/svg+xml'),
        ], [
            'Accept' => 'application/json',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This file type is not accepted by RAGAnything native ingestion. Enable Custom converter for special formats.');

        $this->assertDatabaseMissing('datasets', [
            'dataset_id' => 'native-reject',
        ]);
    }

    public function test_custom_converter_upload_accepts_non_native_file_and_passes_profile_path(): void
    {
        $root = storage_path('framework/testing/pipeline-controller-custom');
        File::deleteDirectory($root);
        config()->set('temporal.storage.shared_root', $root);
        config()->set('file_converter.raganything_supported_extensions', ['pdf']);
        config()->set('file_converter.custom_converter_status_path', '/jobs/{job_id}');
        Http::fake([
            '*temporal/workflows/ingest' => Http::response([
                'workflow_id' => 'ingest-source-custom-workflow',
                'run_id' => 'upload-custom-run-1',
            ]),
        ]);

        $this->actingAsApiUser();

        $response = $this->post('/api/pipeline/controller/files', [
            'dataset_id' => 'custom-converter-test',
            'converter_mode' => 'custom',
            'converter_url' => 'https://converter.example.test',
            'converter_token' => 'secret-user-api-key',
            'converter_start_path' => '/extract',
            'converter_status_path' => '/user-controlled-status',
            'file' => UploadedFile::fake()->create('diagram.svg', 12, 'image/svg+xml'),
        ], [
            'Accept' => 'application/json',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('dataset_id', 'custom-converter-test');

        $profilePath = null;
        Http::assertSent(function ($request) use (&$profilePath): bool {
            $data = $request->data();
            $profilePath = data_get($data, 'workflow_input.custom_converter_profile_path');

            return $request->url() === config('config.hawki_rag_bridge_url').'/temporal/workflows/ingest'
                && data_get($data, 'workflow_input.converter_mode') === 'custom'
                && is_string($profilePath)
                && str_contains($profilePath, 'custom_converter.json')
                && ! str_contains(json_encode($data, JSON_UNESCAPED_SLASHES), 'secret-user-api-key');
        });

        $this->assertIsString($profilePath);
        $this->assertFileExists($profilePath);
        $profile = json_decode(File::get($profilePath), true);
        $this->assertSame('https://converter.example.test', $profile['converter_url']);
        $this->assertSame('/extract', $profile['converter_start_path']);
        $this->assertSame('/jobs/{job_id}', $profile['converter_status_path']);
        $this->assertSame('secret-user-api-key', $profile['converter_token']);
        $this->assertStringNotContainsString('/user-controlled-status', json_encode($profile, JSON_UNESCAPED_SLASHES));

        $task = PipelineTask::query()->where('dataset_id', 'custom-converter-test')->firstOrFail();
        $this->assertStringNotContainsString('secret-user-api-key', json_encode($task->metadata, JSON_UNESCAPED_SLASHES));
        $this->assertSame($task->task_id, $response->json('task_id'));

        File::deleteDirectory($root);
    }

    public function test_custom_converter_upload_uses_saved_settings_without_sending_secret(): void
    {
        $root = storage_path('framework/testing/pipeline-controller-settings');
        $settingsPath = storage_path('framework/testing/pipeline-controller-settings.json');
        File::deleteDirectory($root);
        File::delete($settingsPath);
        config()->set('config.admin_settings_path', $settingsPath);
        config()->set('temporal.storage.shared_root', $root);
        config()->set('file_converter.raganything_supported_extensions', ['pdf']);
        Http::fake([
            '*temporal/workflows/ingest' => Http::response([
                'workflow_id' => 'ingest-source-settings-workflow',
                'run_id' => 'upload-settings-run-1',
            ]),
        ]);

        $this->withSession(['_token' => 'test-token'])
            ->putJson('/api/settings/config', [
                'customConverter' => [
                    'enabled' => true,
                    'supportedExtensions' => '',
                    'apiUrl' => 'https://converter.example.test',
                    'startPath' => '/extract',
                    'apiKey' => 'stored-converter-key',
                ],
                'models' => [
                    'provider' => 'litellm',
                    'graphModel' => 'hawki-ollama-chat',
                    'embeddingModel' => 'hawki-ollama-embedding',
                    'visionModel' => 'hawki-ollama-vision',
                ],
            ], ['X-CSRF-TOKEN' => 'test-token'])
            ->assertOk();

        $this->actingAsApiUser();

        $this->post('/api/pipeline/controller/files', [
            'dataset_id' => 'saved-converter-test',
            'converter_mode' => 'custom',
            'file' => UploadedFile::fake()->create('diagram.svg', 12, 'image/svg+xml'),
        ], [
            'Accept' => 'application/json',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('dataset_id', 'saved-converter-test');

        $profilePath = null;
        Http::assertSent(function ($request) use (&$profilePath): bool {
            $data = $request->data();
            $profilePath = data_get($data, 'workflow_input.custom_converter_profile_path');

            return $request->url() === config('config.hawki_rag_bridge_url').'/temporal/workflows/ingest'
                && data_get($data, 'workflow_input.converter_mode') === 'custom'
                && data_get($data, 'workflow_input.ingestion.provider') === 'litellm'
                && data_get($data, 'workflow_input.ingestion.graph_model') === 'hawki-ollama-chat'
                && data_get($data, 'workflow_input.ingestion.embedding_model') === 'hawki-ollama-embedding'
                && data_get($data, 'workflow_input.ingestion.vision_model') === 'hawki-ollama-vision'
                && is_string($profilePath)
                && ! str_contains(json_encode($data, JSON_UNESCAPED_SLASHES), 'stored-converter-key');
        });

        $this->assertIsString($profilePath);
        $profile = json_decode(File::get($profilePath), true);
        $this->assertSame('https://converter.example.test', $profile['converter_url']);
        $this->assertSame('/extract', $profile['converter_start_path']);
        $this->assertSame('stored-converter-key', $profile['converter_token']);

        File::deleteDirectory($root);
        File::delete($settingsPath);
    }

    public function test_retry_failed_temporal_job_uses_unique_retry_workflow_id(): void
    {
        Http::fake([
            '*temporal/workflows/ingest' => Http::response([
                'workflow_id' => 'ingest-source-source-retry-retry-2-ingest-retry',
                'run_id' => 'retry-run-2',
            ]),
        ]);

        Dataset::query()->create([
            'dataset_id' => 'retry-dataset',
            'name' => 'retry-dataset',
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_retry_dataset',
            'neo4j_namespace' => 'hawki_retry_dataset',
        ]);

        $task = PipelineTask::query()->create([
            'task_id' => 'task-retry-temporal',
            'dataset_id' => 'retry-dataset',
            'status' => PipelineTask::STATUS_FAILED,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinute(),
            'counters' => ['failed' => 1],
            'metadata' => [],
        ]);

        IngestionSource::query()->create([
            'source_id' => 'source-retry',
            'source_url' => 'https://example.test/retry',
            'task_id' => $task->task_id,
            'dataset_id' => $task->dataset_id,
            'content_hash' => 'hash-retry',
            'index_status' => 'failed',
            'raw_storage_path' => '/shared/sources/source-retry/raw/',
            'markdown_storage_path' => '/shared/sources/source-retry/markdown/',
            'metadata' => [
                'request' => [
                    'metadata' => [
                        'source' => 'scraper-task-ui',
                        'max_pages' => 1,
                    ],
                ],
            ],
        ]);

        PipelineJob::query()->create([
            'job_id' => 'ingest-retry',
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_INGEST,
            'source_id' => 'source-retry',
            'source_url' => 'https://example.test/retry',
            'content_hash' => 'hash-retry',
            'status' => PipelineJob::STATUS_FAILED,
            'index_status' => 'failed',
            'error_message' => 'Worker failed',
            'started_at' => now()->subMinutes(4),
            'finished_at' => now()->subMinute(),
            'temporal_workflow_id' => 'ingest-source-source-retry',
            'temporal_run_id' => 'old-run',
            'metadata' => ['retry_count' => 1],
        ]);

        $this->withSession(['_token' => 'test-token'])
            ->postJson('/api/pipeline/tasks/task-retry-temporal/retry', [], ['X-CSRF-TOKEN' => 'test-token'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(fn ($request): bool => $request->url() === config('config.hawki_rag_bridge_url').'/temporal/workflows/ingest'
            && data_get($request->data(), 'workflow_id') === 'ingest-source-source-retry-retry-2-ingest-retry'
            && data_get($request->data(), 'workflow_input.metadata.request.metadata.max_pages') === 1);
    }

    public function test_failed_upload_storage_does_not_create_dataset_task_or_job(): void
    {
        $root = storage_path('framework/testing/pipeline-controller-blocked');
        File::deleteDirectory($root);
        File::ensureDirectoryExists(dirname($root));
        File::put($root, 'not a directory');
        config()->set('temporal.storage.shared_root', $root);
        config()->set('file_converter.raganything_supported_extensions', ['pdf']);

        $this->actingAsApiUser();

        $this->post('/api/pipeline/controller/files', [
            'dataset_id' => 'blocked-controller-dataset',
            'file' => UploadedFile::fake()->create('blocked.pdf', 12, 'application/pdf'),
        ], [
            'Accept' => 'application/json',
        ])
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('dataset_id', 'blocked-controller-dataset')
            ->assertJsonPath('task_id', null)
            ->assertJsonPath('job_id', null);

        $this->assertDatabaseMissing('datasets', [
            'dataset_id' => 'blocked-controller-dataset',
        ]);
        $this->assertDatabaseCount('pipeline_tasks', 0);
        $this->assertDatabaseCount('pipeline_jobs', 0);

        File::delete($root);
    }

    public function test_controller_file_input_exposes_raganything_and_converter_extension_lists(): void
    {
        $this->withoutVite();

        config()->set('file_converter.raganything_supported_extensions', ['pdf', 'txt', 'png', 'webp']);
        config()->set('file_converter.supported_extensions', ['zip']);

        $this->get('/pipeline-controller')
            ->assertOk()
            ->assertSee('pipeline-controller-config', false)
            ->assertSee('"nativeExtensions":["pdf","txt","png","webp"]', false)
            ->assertSee('"customExtensions":["zip"]', false);
    }

    public function test_controller_config_uses_settings_converter_without_exposing_secret_fields(): void
    {
        $this->withoutVite();

        $settingsPath = storage_path('framework/testing/pipeline-controller-config-settings.json');
        File::delete($settingsPath);
        config()->set('config.admin_settings_path', $settingsPath);

        $this->withSession(['_token' => 'test-token'])
            ->putJson('/api/settings/config', [
                'customConverter' => [
                    'enabled' => true,
                    'supportedExtensions' => 'svg',
                    'apiUrl' => 'https://converter.example.test',
                    'startPath' => '/extract',
                    'apiKey' => 'controller-config-secret',
                ],
                'models' => [
                    'provider' => 'litellm',
                    'graphModel' => 'hawki-ollama-chat',
                    'embeddingModel' => 'hawki-ollama-embedding',
                    'visionModel' => 'hawki-ollama-vision',
                ],
            ], ['X-CSRF-TOKEN' => 'test-token'])
            ->assertOk();

        $this->get('/pipeline-controller')
            ->assertOk()
            ->assertSee('"customConverter":{"enabled":true,"configured":true,"supported_extensions":["svg"]}', false)
            ->assertDontSee('https://converter.example.test', false)
            ->assertDontSee('controller-config-secret', false)
            ->assertDontSee('/extract', false);

        File::delete($settingsPath);
    }

    public function test_pipeline_task_cache_can_be_deleted(): void
    {
        $root = storage_path('framework/testing/pipeline-task-delete-storage');
        File::deleteDirectory($root);
        config()->set('temporal.storage.shared_root', $root);

        $taskRoot = $root.'/task-cache-delete';
        $jobRoot = $root.'/job-cache-delete';
        $sourceRoot = $root.'/sources/source-cache-delete';
        File::ensureDirectoryExists($taskRoot);
        File::ensureDirectoryExists($jobRoot.'/pages');
        File::ensureDirectoryExists($sourceRoot.'/raw');
        File::put($taskRoot.'/uploaded.pdf', 'upload');
        File::put($jobRoot.'/pages/page.md', 'raw crawler output');
        File::put($sourceRoot.'/raw/uploaded.pdf', 'raw');

        $task = PipelineTask::query()->create([
            'task_id' => 'task-cache-delete',
            'dataset_id' => 'cache-delete-dataset',
            'status' => PipelineTask::STATUS_COMPLETED,
            'started_at' => now(),
            'finished_at' => now(),
            'counters' => ['jobs_total' => 1, 'jobs_completed' => 1],
            'metadata' => [],
        ]);
        $job = PipelineJob::query()->create([
            'job_id' => 'job-cache-delete',
            'task_id' => $task->task_id,
            'source_id' => 'source-cache-delete',
            'job_type' => PipelineJob::TYPE_CONVERT,
            'status' => PipelineJob::STATUS_COMPLETED,
            'local_path' => $taskRoot.'/uploaded.pdf',
            'started_at' => now(),
            'finished_at' => now(),
            'metadata' => [],
        ]);
        IngestionSource::query()->create([
            'source_id' => 'source-cache-delete',
            'source_url' => 'upload://uploaded.pdf',
            'task_id' => $task->task_id,
            'dataset_id' => $task->dataset_id,
            'raw_storage_path' => $sourceRoot.'/raw/',
            'markdown_storage_path' => $sourceRoot.'/markdown/',
            'index_status' => IngestionSource::STATUS_READY,
        ]);
        $stage = PipelineStageState::query()->create([
            'pipeline_job_id' => $job->id,
            'job_id' => $job->job_id,
            'stage' => 'convert',
            'status' => PipelineJob::STATUS_COMPLETED,
            'counts' => [],
            'metadata' => [],
        ]);

        $this->withSession(['_token' => 'test-token'])
            ->deleteJson('/api/pipeline/tasks/task-cache-delete', [], ['X-CSRF-TOKEN' => 'test-token'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('task_id', 'task-cache-delete')
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('storage_cleanup.ok', true);

        $this->assertDatabaseMissing('pipeline_tasks', [
            'task_id' => 'task-cache-delete',
        ]);
        $this->assertDatabaseMissing('pipeline_jobs', [
            'job_id' => 'job-cache-delete',
        ]);
        $this->assertDatabaseMissing('pipeline_stage_states', [
            'id' => $stage->id,
        ]);
        $this->assertDatabaseMissing('ingestion_sources', [
            'source_id' => 'source-cache-delete',
        ]);
        $this->assertDirectoryDoesNotExist($taskRoot);
        $this->assertDirectoryDoesNotExist($jobRoot);
        $this->assertDirectoryDoesNotExist($sourceRoot);

        File::deleteDirectory($root);
    }

    public function test_pipeline_stage_logs_can_be_viewed_and_downloaded(): void
    {
        $logPath = storage_path('framework/testing/pipeline-stage-logs/comm_logs.json');
        File::ensureDirectoryExists(dirname($logPath));
        File::put($logPath, json_encode([
            'message' => 'pipeline.stage',
            'context' => [
                'event' => 'pipeline.stage',
                'stage' => 'scrape',
                'status' => 'success',
                'job_id' => 'job-stage-logs',
                'pipeline_stage' => 'execution',
                'message' => 'Crawler submitted pages.',
            ],
            'level_name' => 'INFO',
            'datetime' => '2026-06-19T12:00:00+00:00',
        ], JSON_UNESCAPED_SLASHES).PHP_EOL);
        config()->set('logging.channels.communication.path', $logPath);

        $task = PipelineTask::query()->create([
            'task_id' => 'task-stage-logs',
            'dataset_id' => 'logs-dataset',
            'status' => PipelineTask::STATUS_RUNNING,
            'started_at' => now(),
            'counters' => ['jobs_total' => 1],
            'metadata' => ['request' => ['mode' => 'scrape_convert_ingest']],
        ]);
        $job = PipelineJob::query()->create([
            'job_id' => 'job-stage-logs',
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'status' => PipelineJob::STATUS_COMPLETED,
            'source_url' => 'https://example.test/logs',
            'local_path' => '/app/shared/logs-dataset',
            'metadata' => ['events' => []],
        ]);
        PipelineStageState::query()->create([
            'pipeline_job_id' => $job->id,
            'job_id' => $job->job_id,
            'stage' => 'scrape',
            'status' => PipelineJob::STATUS_COMPLETED,
            'counts' => ['pages' => 3],
            'metadata' => ['worker' => 'scraper'],
            'warnings' => [],
            'errors' => [],
        ]);

        $response = $this->getJson('/api/pipeline/tasks/task-stage-logs/stages/scraper/logs')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('log.filename', 'scraper_log_logs-dataset.txt')
            ->assertJsonPath('log.stage', 'scrape')
            ->assertJsonPath('log.label', 'Scraper');

        $text = (string) $response->json('log.text');
        $this->assertStringContainsString('Scraper crawler.log entries', $text);
        $this->assertStringNotContainsString('HAWKI-RAG Scraper stage log', $text);
        $this->assertStringNotContainsString('Job: job-stage-logs', $text);
        $this->assertStringNotContainsString('Crawler submitted pages.', $text);

        $download = $this->get('/api/pipeline/tasks/task-stage-logs/stages/scraper/logs/download')
            ->assertOk();

        $this->assertStringContainsString(
            'filename="scraper_log_logs-dataset.txt"',
            (string) $download->headers->get('content-disposition')
        );
        $downloadText = (string) $download->getContent();
        $this->assertStringContainsString('HAWKI-RAG Scraper stage log', $downloadText);
        $this->assertStringContainsString('Job: job-stage-logs', $downloadText);
        $this->assertStringContainsString('Crawler submitted pages.', $downloadText);

        File::deleteDirectory(dirname($logPath));
    }

    public function test_scrape_stage_log_excludes_direct_scraper_worker_entries(): void
    {
        $runtimeRoot = storage_path('framework/testing/pipeline-stage-runtime-scrape');
        $runtimeLogPath = $runtimeRoot.'/scraper_worker.log';
        File::ensureDirectoryExists($runtimeRoot);
        File::put($runtimeLogPath, implode(PHP_EOL, [
            "2026-06-21 10:00:00 INFO temporal_rag.activities scrape_source:start {'source_id': 'source_scrape_direct', 'raw_dir': '/shared/sources/source_scrape_direct/raw', 'task_queue': 'rag-scraper-task-queue'}",
            "2026-06-21 10:00:01 INFO temporal_rag.activities scrape_source:end {'source_id': 'source_other', 'raw_dir': '/shared/sources/source_other/raw', 'task_queue': 'rag-scraper-task-queue'}",
        ]).PHP_EOL);
        config()->set('config.pipeline_stage_runtime_log_paths.scrape', [$runtimeLogPath]);

        $task = PipelineTask::query()->create([
            'task_id' => 'task-direct-scrape-logs',
            'dataset_id' => 'direct-scrape-dataset',
            'status' => PipelineTask::STATUS_RUNNING,
            'started_at' => now(),
            'counters' => ['jobs_total' => 1],
            'metadata' => ['request' => ['mode' => 'scrape_convert_ingest']],
        ]);
        PipelineJob::query()->create([
            'job_id' => 'job-direct-scrape',
            'task_id' => $task->task_id,
            'source_id' => 'source_scrape_direct',
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'status' => PipelineJob::STATUS_RUNNING,
            'current_stage' => 'scrape_source',
            'source_url' => 'https://example.test/direct-scrape',
            'local_path' => '/shared/sources/source_scrape_direct/raw',
            'metadata' => ['source_id' => 'source_scrape_direct'],
        ]);

        $response = $this->getJson('/api/pipeline/tasks/task-direct-scrape-logs/stages/scrape/logs')
            ->assertOk()
            ->assertJsonPath('success', true);

        $text = (string) $response->json('log.text');
        $this->assertStringNotContainsString('Scraper worker log entries', $text);
        $this->assertStringNotContainsString('scraper_worker.log', $text);
        $this->assertStringNotContainsString('scrape_source:start', $text);
        $this->assertStringNotContainsString('source_scrape_direct', $text);
        $this->assertStringNotContainsString('source_other', $text);

        File::deleteDirectory($runtimeRoot);
    }

    public function test_convert_stage_log_includes_direct_converter_worker_entries(): void
    {
        $runtimeRoot = storage_path('framework/testing/pipeline-stage-runtime-convert');
        $runtimeLogPath = $runtimeRoot.'/converter_worker.log';
        File::ensureDirectoryExists($runtimeRoot);
        File::put($runtimeLogPath, implode(PHP_EOL, [
            "2026-06-21 10:01:00 INFO temporal_rag.activities inspect_and_convert_files:start {'source_id': 'source_convert_direct', 'raw_dir': '/shared/sources/source_convert_direct/raw', 'markdown_dir': '/shared/sources/source_convert_direct/markdown', 'task_queue': 'rag-converter-task-queue'}",
            "2026-06-21 10:01:01 INFO temporal_rag.activities inspect_and_convert_files:end {'source_id': 'source_other', 'markdown_dir': '/shared/sources/source_other/markdown', 'task_queue': 'rag-converter-task-queue'}",
        ]).PHP_EOL);
        config()->set('config.pipeline_stage_runtime_log_paths.convert', [$runtimeLogPath]);

        $task = PipelineTask::query()->create([
            'task_id' => 'task-direct-convert-logs',
            'dataset_id' => 'direct-convert-dataset',
            'status' => PipelineTask::STATUS_RUNNING,
            'started_at' => Carbon::parse('2026-06-21 10:00:30', 'UTC'),
            'counters' => ['jobs_total' => 1],
            'metadata' => ['request' => ['mode' => 'uploaded_file_convert_ingest']],
        ]);
        PipelineJob::query()->create([
            'job_id' => 'job-direct-convert',
            'task_id' => $task->task_id,
            'source_id' => 'source_convert_direct',
            'job_type' => PipelineJob::TYPE_INGEST,
            'status' => PipelineJob::STATUS_RUNNING,
            'current_stage' => 'inspect_and_convert_files',
            'source_url' => 'upload://direct-convert.pdf',
            'local_path' => '/shared/task_controller_upload/direct-convert.pdf',
            'metadata' => ['source_id' => 'source_convert_direct'],
        ]);

        $response = $this->getJson('/api/pipeline/tasks/task-direct-convert-logs/stages/convert/logs')
            ->assertOk()
            ->assertJsonPath('success', true);

        $text = (string) $response->json('log.text');
        $this->assertStringContainsString('Converter worker log entries', $text);
        $this->assertStringContainsString('inspect_and_convert_files:start', $text);
        $this->assertStringContainsString('source_convert_direct', $text);
        $this->assertStringNotContainsString('source_other', $text);

        File::deleteDirectory($runtimeRoot);
    }

    public function test_ingest_stage_log_excludes_old_worker_entries_for_same_source(): void
    {
        $runtimeRoot = storage_path('framework/testing/pipeline-stage-runtime-ingest');
        $runtimeLogPath = $runtimeRoot.'/ingestion_worker.log';
        File::ensureDirectoryExists($runtimeRoot);
        File::put($runtimeLogPath, implode(PHP_EOL, [
            "2026-06-28 08:34:59,723 INFO temporal_rag.activities ingest_markdown_files:start {'source_id': 'source_repeat_upload', 'markdown_dir': '/shared/sources/source_repeat_upload/markdown', 'task_queue': 'rag-ingestion-task-queue'}",
            "2026-06-28 23:45:40,165 INFO temporal_rag.activities ingest_markdown_files:start {'source_id': 'source_repeat_upload', 'markdown_dir': '/shared/sources/source_repeat_upload/markdown', 'task_queue': 'rag-ingestion-task-queue'}",
            "2026-06-28 23:45:41,165 INFO temporal_rag.activities ingest_markdown_files:start {'source_id': 'source_other_upload', 'markdown_dir': '/shared/sources/source_other_upload/markdown', 'task_queue': 'rag-ingestion-task-queue'}",
        ]).PHP_EOL);
        config()->set('config.raganything_runtime_log_paths', []);
        config()->set('config.pipeline_stage_runtime_log_paths.ingest', [$runtimeLogPath]);

        $task = PipelineTask::query()->create([
            'task_id' => 'task-repeat-upload-ingest-logs',
            'dataset_id' => 'repeat-upload-dataset',
            'status' => PipelineTask::STATUS_RUNNING,
            'started_at' => Carbon::parse('2026-06-28 23:45:38', 'UTC'),
            'counters' => ['jobs_total' => 1],
            'metadata' => ['request' => ['mode' => 'uploaded_file_convert_ingest']],
        ]);
        PipelineJob::query()->create([
            'job_id' => 'ingest_current_repeat',
            'task_id' => $task->task_id,
            'source_id' => 'source_repeat_upload',
            'job_type' => PipelineJob::TYPE_INGEST,
            'status' => PipelineJob::STATUS_RUNNING,
            'current_stage' => 'ingest_markdown_files',
            'source_url' => 'upload://repeat.pdf',
            'local_path' => '/shared/task_controller_upload/repeat.pdf',
            'metadata' => ['source_id' => 'source_repeat_upload'],
        ]);

        $response = $this->getJson('/api/pipeline/tasks/task-repeat-upload-ingest-logs/stages/ingest/logs')
            ->assertOk()
            ->assertJsonPath('success', true);

        $text = (string) $response->json('log.text');
        $this->assertStringContainsString('Temporal ingestion worker log entries', $text);
        $this->assertStringContainsString('2026-06-28 23:45:40,165', $text);
        $this->assertStringNotContainsString('2026-06-28 08:34:59,723', $text);
        $this->assertStringNotContainsString('source_other_upload', $text);

        File::deleteDirectory($runtimeRoot);
    }

    public function test_ingest_stage_log_includes_real_raganything_runtime_entries(): void
    {
        $runtimeLogPath = storage_path('framework/testing/raganything-runtime/raganything_runtime.log');
        $runtimeAliasPath = storage_path('framework/testing/raganything-runtime/raganything_runtime_alias.log');
        File::ensureDirectoryExists(dirname($runtimeLogPath));
        if (is_link($runtimeAliasPath) || file_exists($runtimeAliasPath)) {
            unlink($runtimeAliasPath);
        }

        $operationId = 'source_real_ingest:ingest_real_job:doc_real_abc123:ingest';
        $oldOperationId = 'source_real_ingest:ingest_old_job:doc_old_abc123:ingest';
        File::put($runtimeLogPath, implode(PHP_EOL, [
            'level="INFO" logger="api.main" event="api:ingest request_id='.$oldOperationId.' docs=1 graph=True idempotency_key='.$oldOperationId.'"',
            'level="INFO" logger="application.workflows.ingest_logic" event="{"collection": "hawki_raganything-dataset", "event": "application.workflows.stage", "graph": true, "idempotency_key": "'.$oldOperationId.'", "job_id": "ingest_old_job", "stage": "ingest", "status": "started", "total_docs": 1}"',
            'level="INFO" logger="application.workflows.ingest.chunking" event="{"chunks": 1, "doc_id": "doc_old_abc123", "event": "application.workflows.stage", "job_id": "ingest_old_job", "stage": "ingest", "status": "success"}"',
            'level="INFO" logger="api.main" event="api:ingest request_id='.$operationId.' docs=1 graph=True idempotency_key='.$operationId.'"',
            'level="INFO" logger="api.main" event="event=api.request_start request_id='.$operationId.' method=POST path=/ingest body={\"docs\": [{\"id\": \"doc_real_abc123\", \"text\": \"NOISY_RAW_DOC_TEXT RAG-Anything\"}]}"',
            'level="INFO" logger="api.main" event="event=api.request_start request_id=unrelated method=GET path=/health"',
            'level="INFO" logger="application.workflows.ingest_logic" event="{"collection": "hawki_raganything-dataset", "event": "application.workflows.stage", "graph": true, "idempotency_key": "'.$operationId.'", "job_id": "ingest_real_job", "stage": "ingest", "status": "started", "total_docs": 1}"',
            'level="WARNING" logger="application.workflows.ingest.chunking" event="{"doc_id": "doc_real_abc123", "event": "application.workflows.stage", "job_id": "ingest_real_job", "stage": "ingest", "status": "partial", "warnings": ["metadata title is missing."]}"',
            'level="DEBUG" logger="application.workflows.ingest.chunking" event="ingest:doc doc_real_abc123 chunks=209"',
            'level="INFO" logger="application.workflows.ingest.chunking" event="{"chunks": 209, "doc_id": "doc_real_abc123", "event": "application.workflows.stage", "job_id": "ingest_real_job", "stage": "ingest", "status": "success"}"',
            'level="INFO" logger="application.workflows.ingest.vector_commit" event="{"event": "application.workflows.stage", "idempotency_key": "'.$operationId.'", "job_id": "ingest_real_job", "pipeline_stage": "embedding", "points": 209, "stage": "ingest", "status": "success"}"',
            'level="INFO" logger="application.service" event="RAG-Anything graph insert requested doc_id=doc_real_abc123 rag_doc_id=rag-doc file=intermediate-progress.md blocks=1 chars=42"',
            'level="INFO" logger="application.service" event="RAG-Anything graph export doc_id=doc_real_abc123 file=intermediate-progress.md edges_total=3 triplets=2"',
            'level="INFO" logger="application.service" event="RAG-Anything graph insert requested doc_id=unrelated_doc rag_doc_id=other file=other.md blocks=1 chars=12"',
        ]).PHP_EOL);
        symlink($runtimeLogPath, $runtimeAliasPath);

        config()->set('config.raganything_runtime_log_paths', [$runtimeLogPath, $runtimeAliasPath]);

        $task = PipelineTask::query()->create([
            'task_id' => 'task-raganything-logs',
            'dataset_id' => 'raganything-dataset',
            'status' => PipelineTask::STATUS_RUNNING,
            'started_at' => now(),
            'counters' => ['jobs_total' => 1],
            'metadata' => ['request' => ['mode' => 'uploaded_file_convert_ingest']],
        ]);
        $job = PipelineJob::query()->create([
            'job_id' => 'ingest_real_job',
            'task_id' => $task->task_id,
            'source_id' => 'source_real_ingest',
            'job_type' => PipelineJob::TYPE_INGEST,
            'status' => PipelineJob::STATUS_RUNNING,
            'current_stage' => 'ingest',
            'source_url' => 'upload://Intermediate progress.pdf',
            'local_path' => '/shared/task_controller_upload/intermediate-progress.pdf',
            'metadata' => ['source_id' => 'source_real_ingest'],
        ]);
        PipelineStageState::query()->create([
            'pipeline_job_id' => $job->id,
            'job_id' => $job->job_id,
            'stage' => 'ingest',
            'status' => PipelineJob::STATUS_RUNNING,
            'counts' => [],
            'metadata' => [],
            'warnings' => [],
            'errors' => [],
        ]);

        $response = $this->getJson('/api/pipeline/tasks/task-raganything-logs/stages/ingest/logs')
            ->assertOk()
            ->assertJsonPath('success', true);

        $text = (string) $response->json('log.text');
        $this->assertStringContainsString('RAG-Anything runtime log entries', $text);
        $this->assertStringContainsString('api:ingest request_id='.$operationId, $text);
        $this->assertStringContainsString('application.workflows.stage', $text);
        $this->assertStringContainsString('"pipeline_stage": "embedding"', $text);
        $this->assertStringContainsString('ingest:doc doc_real_abc123 chunks=209', $text);
        $this->assertStringContainsString('RAG-Anything graph insert requested doc_id=doc_real_abc123', $text);
        $this->assertStringContainsString('RAG-Anything graph export doc_id=doc_real_abc123', $text);
        $this->assertStringNotContainsString('Ingest job and stage records', $text);
        $this->assertStringNotContainsString('NOISY_RAW_DOC_TEXT', $text);
        $this->assertStringNotContainsString('api.request_start', $text);
        $this->assertStringNotContainsString('path=/health', $text);
        $this->assertStringNotContainsString('unrelated_doc', $text);
        $this->assertStringNotContainsString('ingest_old_job', $text);
        $this->assertStringNotContainsString('doc_old_abc123', $text);
        $this->assertSame(1, substr_count($text, 'api:ingest request_id='.$operationId));

        $download = $this->get('/api/pipeline/tasks/task-raganything-logs/stages/ingest/logs/download')
            ->assertOk();
        $downloadText = (string) $download->getContent();
        $this->assertStringContainsString('Ingest job and stage records', $downloadText);
        $this->assertStringNotContainsString('NOISY_RAW_DOC_TEXT', $downloadText);
        $this->assertStringNotContainsString('api.request_start', $downloadText);

        File::deleteDirectory(dirname($runtimeLogPath));
    }
}
