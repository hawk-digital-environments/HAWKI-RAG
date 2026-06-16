<?php

namespace Tests\Feature;

use App\Models\PipelineJob;
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
            ->assertSee('pipeline-nav-dashboard', false)
            ->assertSee('pipeline-nav-menu', false)
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
}
