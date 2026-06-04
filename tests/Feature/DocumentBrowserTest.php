<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Document;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DocumentBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_page_lists_ingested_documents(): void
    {
        $this->withoutVite();

        $document = $this->createIngestedDocument();

        $this->get('/documents')
            ->assertOk()
            ->assertSee('Document Browser');

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

    private function createIngestedDocument(): Document
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
            'metadata_json' => [
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
            ],
            'status' => Document::STATUS_COMPLETED,
        ]);
    }
}
