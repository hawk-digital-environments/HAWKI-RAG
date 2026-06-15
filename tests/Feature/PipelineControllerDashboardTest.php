<?php

namespace Tests\Feature;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
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
            ->assertSee('Convert and Ingest Document')
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

    public function test_uploading_file_creates_skipped_convert_job_metadata_without_event_publish(): void
    {
        $root = storage_path('framework/testing/pipeline-controller');
        File::deleteDirectory($root);
        config()->set('temporal.storage.shared_root', $root);
        config()->set('file_converter.supported_extensions', ['pdf']);

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
            ->assertJsonPath('datasetId', 'controller-test');

        $taskId = $response->json('taskId');
        $jobId = $response->json('jobId');

        $this->assertDatabaseHas('datasets', [
            'dataset_id' => 'controller-test',
        ]);
        $this->assertDatabaseHas('pipeline_tasks', [
            'task_id' => $taskId,
            'dataset_id' => 'controller-test',
            'status' => PipelineTask::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('pipeline_jobs', [
            'job_id' => $jobId,
            'task_id' => $taskId,
            'job_type' => PipelineJob::TYPE_CONVERT,
            'source_url' => 'upload://sample.pdf',
            'status' => PipelineJob::STATUS_SKIPPED,
            'current_stage' => 'upload.metadata_stored',
        ]);

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
