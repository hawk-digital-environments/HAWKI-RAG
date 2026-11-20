<?php

namespace Tests\Feature\Documents;

use App\Models\PipelineJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DocumentBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_page_redirects_to_dataset_browser(): void
    {
        $this->withoutVite();

        $this->get('/documents')
            ->assertRedirect('/datasets');
    }

    public function test_document_detail_returns_not_found_for_non_numeric_placeholder_id(): void
    {
        $this->actingAsApiUser();

        $this->getJson('/api/documents/sample-document-id')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_uploaded_source_document_can_be_downloaded_by_upload_uri(): void
    {
        $sourceUrl = 'upload://NordVPN_Instructions_V1.pdf';
        $uploadRoot = storage_path('framework/testing/shared-uploads');
        $path = $uploadRoot.'/task-upload/NordVPN_Instructions_V1.pdf';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '%PDF test document');
        $this->configureUploadDownloadRoot($uploadRoot);

        PipelineJob::query()->create([
            'job_id' => 'ingest-upload-download',
            'task_id' => 'task-upload-download',
            'job_type' => PipelineJob::TYPE_INGEST,
            'source_url' => $sourceUrl,
            'local_path' => $path,
            'content_hash' => hash_file('sha256', $path),
            'status' => PipelineJob::STATUS_COMPLETED,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'metadata' => [
                'upload' => [
                    'original_filename' => 'NordVPN_Instructions_V1.pdf',
                    'local_path' => $path,
                ],
            ],
        ]);

        $this->actingAsApiUser();

        $this->get('/api/documents/uploads/download?'.http_build_query(['source_url' => $sourceUrl]))
            ->assertOk()
            ->assertDownload('NordVPN_Instructions_V1.pdf');
    }

    public function test_uploaded_source_document_can_be_recovered_from_shared_upload_storage_without_database_record(): void
    {
        $sourceUrl = 'upload://NordVPN_Instructions_V1.pdf';
        $uploadRoot = storage_path('framework/testing/shared-uploads');
        $path = $uploadRoot.'/task_controller_upload_20260623_021720_3zvjvk/nordvpn-instructions-v1-7svxes3l.pdf';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '%PDF recovered document');
        $this->configureUploadDownloadRoot($uploadRoot);

        $this->actingAsApiUser();

        $this->get('/api/documents/uploads/download?'.http_build_query([
            'source_url' => $sourceUrl,
            'content_hash' => '1c693e60d1cda122f4e3d61a7cbf8c4eb72b82228da494d27455885b0a7dc54f',
        ]))
            ->assertOk()
            ->assertDownload('NordVPN_Instructions_V1.pdf');
    }

    public function test_uploaded_source_document_download_rejects_paths_outside_shared_roots(): void
    {
        $sourceUrl = 'upload://outside.pdf';
        $uploadRoot = storage_path('framework/testing/shared-uploads');
        $outsidePath = storage_path('framework/testing/outside/outside.pdf');
        File::ensureDirectoryExists(dirname($outsidePath));
        File::put($outsidePath, 'outside file');
        $this->configureUploadDownloadRoot($uploadRoot);

        PipelineJob::query()->create([
            'job_id' => 'ingest-upload-outside',
            'task_id' => 'task-upload-outside',
            'job_type' => PipelineJob::TYPE_INGEST,
            'source_url' => $sourceUrl,
            'local_path' => $outsidePath,
            'content_hash' => hash_file('sha256', $outsidePath),
            'status' => PipelineJob::STATUS_COMPLETED,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'metadata' => [],
        ]);

        $this->actingAsApiUser();

        $this->getJson('/api/documents/uploads/download?'.http_build_query(['source_url' => $sourceUrl]))
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    private function configureUploadDownloadRoot(string $root): void
    {
        File::ensureDirectoryExists($root);
        config()->set('temporal.storage.shared_root', $root);
        config()->set('config.pipeline_root', $root);
        config()->set('config.shared_root', $root);
        config()->set('config.crawled_data_root', $root);
    }
}
