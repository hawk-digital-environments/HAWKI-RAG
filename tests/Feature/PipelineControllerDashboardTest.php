<?php

namespace Tests\Feature;

use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Models\PipelineTask;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PipelineControllerDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_controller_has_its_own_page_and_is_removed_from_playground(): void
    {
        $this->withoutVite();

        $this->get('/pipeline-controller')
            ->assertOk()
            ->assertSee('Pipeline Controller')
            ->assertSee('Convert and Ingest File')
            ->assertSee('Scraper Tasks')
            ->assertSee('pipeline-nav', false)
            ->assertSee('pipeline-controller-refresh', false)
            ->assertSee('pipeline-stage-log-viewer', false)
            ->assertSee('Stage logs')
            ->assertSee('pipeline-file-form', false)
            ->assertSee('pipeline-task-select', false);

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
        config()->set('file_converter.supported_extensions', []);
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
            'file' => UploadedFile::fake()->create('sample.svg', 12, 'image/svg+xml'),
        ], [
            'Accept' => 'application/json',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('datasetId', 'controller-test')
            ->assertJsonPath('task.stages.scrape.status', 'n/a')
            ->assertJsonPath('task.stages.convert.status', 'processing')
            ->assertJsonPath('task.stages.ingest.status', PipelineJob::STATUS_QUEUED);

        $taskId = $response->json('taskId');
        $jobId = $response->json('jobId');

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
            'source_url' => 'upload://sample.svg',
            'status' => PipelineJob::STATUS_RUNNING,
            'current_stage' => 'temporal.workflow_started',
            'temporal_workflow_id' => 'ingest-source-upload-workflow',
            'temporal_run_id' => 'upload-run-1',
        ]);
        $this->assertDatabaseHas('ingestion_sources', [
            'source_url' => 'upload://sample.svg',
            'task_id' => $taskId,
            'dataset_id' => 'controller-test',
            'index_status' => 'running',
            'temporal_workflow_id' => 'ingest-source-upload-workflow',
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === config('temporal.bridge_url').'/temporal/workflows/ingest'
            && data_get($request->data(), 'workflow_input.upload.original_filename') === 'sample.svg'
            && data_get($request->data(), 'workflow_input.upload.local_path') !== null
            && data_get($request->data(), 'workflow_input.ingestion.graph') === false);

        File::deleteDirectory($root);
    }

    public function test_failed_upload_storage_does_not_create_dataset_task_or_job(): void
    {
        $root = storage_path('framework/testing/pipeline-controller-blocked');
        File::deleteDirectory($root);
        File::ensureDirectoryExists(dirname($root));
        File::put($root, 'not a directory');
        config()->set('temporal.storage.shared_root', $root);
        config()->set('file_converter.supported_extensions', ['pdf']);

        $this->actingAsApiUser();

        $this->post('/api/pipeline/controller/files', [
            'dataset_id' => 'blocked-controller-dataset',
            'file' => UploadedFile::fake()->create('blocked.pdf', 12, 'application/pdf'),
        ], [
            'Accept' => 'application/json',
        ])
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('datasetId', 'blocked-controller-dataset')
            ->assertJsonPath('taskId', null)
            ->assertJsonPath('jobId', null);

        $this->assertDatabaseMissing('datasets', [
            'dataset_id' => 'blocked-controller-dataset',
        ]);
        $this->assertDatabaseCount('pipeline_tasks', 0);
        $this->assertDatabaseCount('pipeline_jobs', 0);

        File::delete($root);
    }

    public function test_controller_file_input_uses_configured_converter_extensions(): void
    {
        $this->withoutVite();

        config()->set('file_converter.supported_extensions', ['pdf', 'txt', 'png', 'webp', 'zip']);

        $this->get('/pipeline-controller')
            ->assertOk()
            ->assertSee('accept=".pdf,.txt,.png,.webp,.zip"', false)
            ->assertSee('data-supported-extensions="pdf,txt,png,webp,zip"', false);
    }

    public function test_pipeline_task_cache_can_be_deleted(): void
    {
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
            'job_type' => PipelineJob::TYPE_CONVERT,
            'status' => PipelineJob::STATUS_COMPLETED,
            'started_at' => now(),
            'finished_at' => now(),
            'metadata' => [],
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
            ->deleteJson('/pipeline/tasks/task-cache-delete', [], ['X-CSRF-TOKEN' => 'test-token'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('taskId', 'task-cache-delete')
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('pipeline_tasks', [
            'task_id' => 'task-cache-delete',
        ]);
        $this->assertDatabaseMissing('pipeline_jobs', [
            'job_id' => 'job-cache-delete',
        ]);
        $this->assertDatabaseMissing('pipeline_stage_states', [
            'id' => $stage->id,
        ]);
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

        $response = $this->getJson('/pipeline/tasks/task-stage-logs/stages/scraper/logs')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('log.filename', 'scraper_log_logs-dataset.txt')
            ->assertJsonPath('log.stage', 'scrape')
            ->assertJsonPath('log.label', 'Scraper');

        $text = (string) $response->json('log.text');
        $this->assertStringContainsString('HAWKI-RAG Scraper stage log', $text);
        $this->assertStringContainsString('Job: job-stage-logs', $text);
        $this->assertStringContainsString('Crawler submitted pages.', $text);

        $download = $this->get('/pipeline/tasks/task-stage-logs/stages/scraper/logs/download')
            ->assertOk();

        $this->assertStringContainsString(
            'filename="scraper_log_logs-dataset.txt"',
            (string) $download->headers->get('content-disposition')
        );
        $this->assertStringContainsString('HAWKI-RAG Scraper stage log', $download->getContent());

        File::deleteDirectory(dirname($logPath));
    }
}
