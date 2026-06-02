<?php

namespace Tests\Feature;

use App\Models\JobProcessingState;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Models\ScrapedElement;
use App\Services\FileConverter\DocumentConverter;
use App\Services\Pipeline\EventHandlers\ConverterEventHandler;
use App\Services\Pipeline\EventHandlers\IngestionEventHandler;
use App\Services\Pipeline\EventHandlers\ScraperEventHandler;
use App\Services\Pipeline\PipelineEvent;
use App\Services\Pipeline\PipelineEventBus;
use App\Services\ScrapeService\ScraperPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class PipelineEventLayerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('communication.rabbitmq.pipeline_events.enabled', false);
        config()->set('file_converter.supported_extensions', ['pdf']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/pipeline-events'));

        parent::tearDown();
    }

    public function test_pipeline_events_always_include_required_payload_fields(): void
    {
        $event = PipelineEvent::normalize(PipelineEvent::SCRAPE_REQUESTED, [
            'task_id' => 'task-event-payload',
            'source_url' => 'https://example.test/page',
        ]);

        foreach (PipelineEvent::REQUIRED_PAYLOAD_FIELDS as $field) {
            $this->assertArrayHasKey($field, $event);
        }

        $this->assertSame(PipelineEvent::SCRAPE_REQUESTED, $event['event_type']);
        $this->assertSame(PipelineJob::TYPE_SCRAPE, $event['job_type']);
        $this->assertNotEmpty($event['job_id']);
        $this->assertIsArray($event['metadata']);
    }

    public function test_scraper_consumer_records_already_scraped_urls_as_skipped_jobs(): void
    {
        $this->mock(ScraperPipelineService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('execute');
        });

        $task = $this->task('task-event-scrape-skip');
        $url = 'https://already.example/page';

        ScrapedElement::query()->create([
            'uuid' => (string) Str::uuid(),
            'title' => 'Already scraped',
            'page_url' => $url,
            'page_url_hash' => hash('sha256', $url),
            'content_hash' => hash('sha256', $url),
            'job_id' => 'existing-scrape-job',
        ]);

        app(ScraperEventHandler::class)->handle(PipelineEvent::normalize(PipelineEvent::SCRAPE_REQUESTED, [
            'task_id' => $task->task_id,
            'job_id' => 'scrape-event-skip',
            'dataset_id' => $task->dataset_id,
            'profile_id' => $task->profile_id,
            'source_url' => $url,
            'status' => PipelineJob::STATUS_PENDING,
        ]));

        $this->assertDatabaseHas('pipeline_jobs', [
            'task_id' => $task->task_id,
            'job_id' => 'scrape-event-skip',
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => $url,
            'status' => PipelineJob::STATUS_SKIPPED,
        ]);

        $task->refresh();
        $this->assertSame(1, $task->counters['scrape_jobs']);
        $this->assertSame(1, $task->counters['skipped']);
    }

    public function test_converter_consumer_records_cached_conversions_as_skipped_jobs(): void
    {
        $this->mock(DocumentConverter::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('requestDocumentToMarkdown');
        });

        $task = $this->task('task-event-convert-skip');
        $root = storage_path('framework/testing/pipeline-events/convert');
        $sourceFile = "{$root}/handbook.pdf";
        File::ensureDirectoryExists($root);
        File::put($sourceFile, '%PDF-1.4 fake test file');

        $contentHash = hash_file('sha256', $sourceFile);
        $outputDir = "{$root}/converted_handbook";
        File::ensureDirectoryExists($outputDir);
        File::put("{$outputDir}/handbook.md", '# Converted handbook');
        File::put("{$outputDir}/conversion_meta.json", json_encode([
            'converted_id' => $contentHash,
            'files' => ['handbook.md'],
        ], JSON_THROW_ON_ERROR));

        app(ConverterEventHandler::class)->handle(PipelineEvent::normalize(PipelineEvent::FILE_DISCOVERED, [
            'task_id' => $task->task_id,
            'job_id' => 'convert-event-skip',
            'parent_job_id' => 'scrape-parent',
            'dataset_id' => $task->dataset_id,
            'profile_id' => $task->profile_id,
            'source_url' => 'https://example.test/handbook.pdf',
            'local_path' => $sourceFile,
            'content_hash' => $contentHash,
            'status' => PipelineJob::STATUS_PENDING,
        ]));

        $this->assertDatabaseHas('pipeline_jobs', [
            'task_id' => $task->task_id,
            'job_id' => 'convert-event-skip',
            'job_type' => PipelineJob::TYPE_CONVERT,
            'local_path' => $sourceFile,
            'status' => PipelineJob::STATUS_SKIPPED,
        ]);

        $task->refresh();
        $this->assertSame(1, $task->counters['convert_jobs']);
        $this->assertSame(1, $task->counters['skipped']);
    }

    public function test_ingestion_consumer_ingests_scraped_content_and_records_graph_update(): void
    {
        Http::fake([
            '*/ingest' => Http::response(['ok' => true, 'documents' => 1], 200),
        ]);

        $task = $this->task('task-event-ingest');
        $root = storage_path('framework/testing/pipeline-events/ingest');
        $markdownPath = "{$root}/page.md";
        File::ensureDirectoryExists($root);
        File::put($markdownPath, "# Page\n\nScraped markdown content.");

        app(IngestionEventHandler::class)->handle(PipelineEvent::normalize(PipelineEvent::PAGE_SCRAPED, [
            'task_id' => $task->task_id,
            'job_id' => 'scrape-event-completed',
            'dataset_id' => $task->dataset_id,
            'profile_id' => $task->profile_id,
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => 'https://example.test/page',
            'local_path' => $markdownPath,
            'content_hash' => hash_file('sha256', $markdownPath),
            'status' => PipelineJob::STATUS_COMPLETED,
        ]));

        $ingestJob = PipelineJob::query()
            ->where('task_id', $task->task_id)
            ->where('job_type', PipelineJob::TYPE_INGEST)
            ->firstOrFail();

        $this->assertSame(PipelineJob::STATUS_COMPLETED, $ingestJob->status);
        $this->assertSame('scrape-event-completed', $ingestJob->parent_job_id);

        $this->assertDatabaseHas('job_processing_state', [
            'job_id' => $ingestJob->job_id,
            'stage' => JobProcessingState::STAGE_RAG_INGESTION,
            'status' => JobProcessingState::STATUS_COMPLETED,
        ]);

        $this->assertDatabaseHas('pipeline_jobs', [
            'task_id' => $task->task_id,
            'parent_job_id' => $ingestJob->job_id,
            'job_type' => PipelineJob::TYPE_GRAPH,
            'status' => PipelineJob::STATUS_COMPLETED,
        ]);

        Http::assertSent(fn ($request) => $request->url() === 'http://hawki_rag_bridge:8000/ingest'
            && $request['docs'][0]['payload']['task_id'] === $task->task_id);

        $task->refresh();
        $this->assertSame(1, $task->counters['ingested']);
        $this->assertSame(1, $task->counters['graph_updated']);
    }

    public function test_ingestion_failures_are_recorded_on_the_child_ingest_job(): void
    {
        $task = $this->task('task-event-ingest-failed');
        $root = storage_path('framework/testing/pipeline-events/ingest-failed');
        $markdownPath = "{$root}/page.md";
        File::ensureDirectoryExists($root);
        File::put($markdownPath, "# Page\n\nScraped markdown content.");

        app(IngestionEventHandler::class)->failed(PipelineEvent::normalize(PipelineEvent::PAGE_SCRAPED, [
            'task_id' => $task->task_id,
            'job_id' => 'scrape-event-source',
            'dataset_id' => $task->dataset_id,
            'profile_id' => $task->profile_id,
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => 'https://example.test/page',
            'local_path' => $markdownPath,
            'content_hash' => hash_file('sha256', $markdownPath),
            'status' => PipelineJob::STATUS_COMPLETED,
        ]), new RuntimeException('Bridge unavailable'), 3, 3);

        $ingestJob = PipelineJob::query()
            ->where('task_id', $task->task_id)
            ->where('job_type', PipelineJob::TYPE_INGEST)
            ->firstOrFail();

        $this->assertSame(PipelineJob::STATUS_FAILED, $ingestJob->status);
        $this->assertSame('scrape-event-source', $ingestJob->parent_job_id);
        $this->assertDatabaseMissing('pipeline_jobs', [
            'job_id' => 'scrape-event-source',
            'status' => PipelineJob::STATUS_FAILED,
        ]);
        $this->assertDatabaseHas('job_processing_state', [
            'job_id' => $ingestJob->job_id,
            'stage' => JobProcessingState::STAGE_RAG_INGESTION,
            'status' => JobProcessingState::STATUS_FAILED,
        ]);
    }

    public function test_failed_events_keep_original_task_and_job_identity(): void
    {
        $failed = app(PipelineEventBus::class)->publishFailed(PipelineEvent::normalize(PipelineEvent::CONVERT_REQUESTED, [
            'task_id' => 'task-event-failed',
            'job_id' => 'convert-event-failed',
            'parent_job_id' => 'scrape-parent',
            'dataset_id' => 'dataset-events',
            'profile_id' => 'profile-events',
            'job_type' => PipelineJob::TYPE_CONVERT,
            'source_url' => 'https://example.test/file.pdf',
            'local_path' => '/app/shared/file.pdf',
            'content_hash' => 'sha256-file',
        ]), new RuntimeException('Conversion failed'));

        foreach (PipelineEvent::REQUIRED_PAYLOAD_FIELDS as $field) {
            $this->assertArrayHasKey($field, $failed);
        }

        $this->assertSame(PipelineEvent::JOB_FAILED, $failed['event_type']);
        $this->assertSame('task-event-failed', $failed['task_id']);
        $this->assertSame('convert-event-failed', $failed['job_id']);
        $this->assertSame(PipelineJob::TYPE_CONVERT, $failed['job_type']);
        $this->assertSame(PipelineEvent::CONVERT_REQUESTED, $failed['metadata']['original_event_type']);
    }

    private function task(string $taskId): PipelineTask
    {
        return PipelineTask::query()->create([
            'task_id' => $taskId,
            'dataset_id' => 'dataset-events',
            'profile_id' => 'profile-events',
            'status' => PipelineTask::STATUS_RUNNING,
            'started_at' => now(),
            'counters' => [],
            'metadata' => [],
        ]);
    }
}
