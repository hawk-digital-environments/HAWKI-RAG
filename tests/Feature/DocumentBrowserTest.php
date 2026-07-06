<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Document;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DocumentBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_page_lists_ingested_documents(): void
    {
        $this->withoutVite();

        $document = $this->createIngestedDocument();

        $this->get('/documents')
            ->assertRedirect('/heaps');

        $this->actingAsApiUser();

        $this->getJson('/api/documents?dataset_id=browser-dataset')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('documents.0.id', $document->id)
            ->assertJsonPath('documents.0.datasetId', 'browser-dataset')
            ->assertJsonPath('documents.0.contentType', 'text/markdown')
            ->assertJsonPath('documents.0.contentHash', $document->checksum_sha256)
            ->assertJsonPath('documents.0.qdrantStatus', 'indexed')
            ->assertJsonPath('documents.0.neo4jStatus', 'indexed')
            ->assertJsonPath('documents.0.taskId', 'task-browser')
            ->assertJsonPath('documents.0.jobId', 'ingest-browser');
    }

    public function test_document_detail_shows_markdown_index_counts_and_related_jobs(): void
    {
        $document = $this->createIngestedDocument();

        $this->actingAsApiUser();

        $this->getJson("/api/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('document.id', $document->id)
            ->assertJsonPath('document.sourceUrl', 'https://example.test/browser')
            ->assertJsonPath('document.localPath', $document->storage_path)
            ->assertJsonPath('document.markdownPreview', "# Browser document\n\nIndexed Markdown content.")
            ->assertJsonPath('document.qdrantPointCount', 4)
            ->assertJsonPath('document.neo4jEntityCount', 6)
            ->assertJsonPath('document.neo4jRelationCount', 5)
            ->assertJsonFragment([
                'taskId' => 'task-browser',
                'jobId' => 'scrape-browser',
                'jobType' => PipelineJob::TYPE_SCRAPE,
            ])
            ->assertJsonFragment([
                'taskId' => 'task-browser',
                'jobId' => 'ingest-browser',
                'jobType' => PipelineJob::TYPE_INGEST,
            ]);
    }

    public function test_document_detail_falls_back_to_live_neo4j_entity_count_when_summary_omits_entities(): void
    {
        $document = $this->createIngestedDocument([
            'bridge_response' => [
                'ok' => true,
                'points' => 146,
                'summary' => [
                    'graph' => ['enabled' => true],
                    'graph_preview' => [
                        'planned_entities' => null,
                        'total_triplets' => 6,
                    ],
                ],
            ],
        ]);

        Http::fake([
            'http://neo4j.test/*' => Http::response([
                'results' => [[
                    'data' => [[
                        'row' => [7, 6],
                    ]],
                ]],
                'errors' => [],
            ]),
        ]);
        config()->set('config.neo4j_http_url', 'http://neo4j.test');

        $this->actingAsApiUser();

        $this->getJson("/api/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('document.qdrantPointCount', 146)
            ->assertJsonPath('document.neo4jEntityCount', 7)
            ->assertJsonPath('document.neo4jRelationCount', 6);
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

        $this->get('/documents/uploads/download?'.http_build_query(['source_url' => $sourceUrl]))
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

        $this->get('/documents/uploads/download?'.http_build_query([
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

        $this->getJson('/documents/uploads/download?'.http_build_query(['source_url' => $sourceUrl]))
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    private function createIngestedDocument(array $metadataOverrides = []): Document
    {
        Dataset::query()->create([
            'dataset_id' => 'browser-dataset',
            'name' => 'Browser Dataset',
            'description' => null,
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_browser_dataset',
            'neo4j_namespace' => 'hawki_browser_dataset',
            'created_at' => now(),
        ]);

        PipelineTask::query()->create([
            'task_id' => 'task-browser',
            'dataset_id' => 'browser-dataset',
            'status' => PipelineTask::STATUS_COMPLETED,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinute(),
            'counters' => ['ingested' => 1],
            'metadata' => [],
        ]);

        PipelineJob::query()->create([
            'job_id' => 'scrape-browser',
            'task_id' => 'task-browser',
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => 'https://example.test/browser',
            'local_path' => '/app/shared/browser/page.html',
            'content_hash' => hash('sha256', 'browser-source'),
            'status' => PipelineJob::STATUS_COMPLETED,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
            'metadata' => [],
        ]);

        PipelineJob::query()->create([
            'job_id' => 'ingest-browser',
            'task_id' => 'task-browser',
            'parent_job_id' => 'scrape-browser',
            'job_type' => PipelineJob::TYPE_INGEST,
            'source_url' => 'https://example.test/browser',
            'local_path' => storage_path('framework/testing/documents/browser.md'),
            'content_hash' => hash('sha256', 'browser-markdown'),
            'status' => PipelineJob::STATUS_COMPLETED,
            'started_at' => now()->subMinutes(3),
            'finished_at' => now()->subMinutes(2),
            'metadata' => [],
        ]);

        $path = storage_path('framework/testing/documents/browser.md');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "# Browser document\n\nIndexed Markdown content.");

        $metadata = array_replace_recursive([
            'task_id' => 'task-browser',
            'job_id' => 'ingest-browser',
            'event_id' => 'event-browser',
            'qdrant_collection' => 'hawki_browser_dataset',
            'neo4j_namespace' => 'hawki_browser_dataset',
            'bridge_response' => [
                'ok' => true,
                'points' => 4,
                'summary' => [
                    'graph' => ['enabled' => true],
                    'graph_preview' => [
                        'planned_entities' => 6,
                        'total_triplets' => 5,
                    ],
                ],
            ],
        ], $metadataOverrides);

        return Document::query()->create([
            'external_id' => 'ingest-browser',
            'dataset_id' => 'browser-dataset',
            'collection' => 'hawki_browser_dataset',
            'source_type' => Document::SOURCE_SCRAPE,
            'source_url' => 'https://example.test/browser',
            'original_filename' => 'browser.md',
            'storage_path' => $path,
            'mime_type' => 'text/markdown',
            'file_size' => filesize($path),
            'checksum_sha256' => hash_file('sha256', $path),
            'title' => 'Browser document',
            'metadata_json' => $metadata,
            'status' => Document::STATUS_COMPLETED,
        ]);
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
