<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\JobProcessingState;
use App\Models\PipelineEventRecord;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Models\ScrapedElement;
use App\Services\FileConverter\DocumentConverter;
use App\Services\Pipeline\EventHandlers\ConverterEventHandler;
use App\Services\Pipeline\EventHandlers\IngestionEventHandler;
use App\Services\Pipeline\EventHandlers\ScrapeMonitorEventHandler;
use App\Services\Pipeline\EventHandlers\ScraperEventHandler;
use App\Services\Pipeline\PipelineEvent;
use App\Services\Pipeline\PipelineEventBus;
use App\Services\Rag\RagRabbitMQ;
use App\Services\ScrapeService\ScrapeService;
use App\Services\ScrapeService\Data\ScrapeJobRequest;
use App\Services\ScrapeService\Data\ScrapeRequestResult;
use App\Services\ScrapeService\ScraperPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
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

    public function test_scraper_consumer_creates_output_directory_before_submit(): void
    {
        config()->set('scraper.storage_path', storage_path('framework/testing/pipeline-events/scrape-output'));
        $outputDir = null;
        $this->mock(ScraperPipelineService::class, function (MockInterface $mock) use (&$outputDir): void {
            $mock->shouldReceive('execute')
                ->once()
                ->withArgs(function (ScrapeJobRequest $request) use (&$outputDir): bool {
                    $outputDir = $request->outputDir;
                    $this->assertDirectoryExists($request->outputDir);
                    $this->assertTrue(is_writable($request->outputDir));

                    return true;
                })
                ->andReturn(ScrapeRequestResult::success('scrape-event-output-dir', 'submitted'));
        });

        $task = $this->task('task-event-scrape-output-dir');
        app(ScraperEventHandler::class)->handle(PipelineEvent::normalize(PipelineEvent::SCRAPE_REQUESTED, [
            'task_id' => $task->task_id,
            'job_id' => 'scrape-event-output-dir',
            'dataset_id' => $task->dataset_id,
            'source_url' => 'https://example.test/output-dir',
            'status' => PipelineJob::STATUS_PENDING,
        ]));

        $this->assertNotNull($outputDir);
        $this->assertDatabaseHas('pipeline_jobs', [
            'task_id' => $task->task_id,
            'job_id' => 'scrape-event-output-dir',
            'status' => PipelineJob::STATUS_RUNNING,
        ]);
    }

    public function test_scrape_monitor_requested_events_are_recorded_for_timeline(): void
    {
        $event = app(PipelineEventBus::class)->publish(PipelineEvent::SCRAPE_MONITOR_REQUESTED, [
            'task_id' => 'task-event-monitor-timeline',
            'job_id' => 'scrape-event-monitor-timeline',
            'source_url' => 'https://example.test/monitor',
            'local_path' => '/app/shared/task-event-monitor-timeline/scrape-event-monitor-timeline',
            'status' => PipelineJob::STATUS_RUNNING,
        ]);

        $this->assertSame(PipelineEvent::SCRAPE_MONITOR_REQUESTED, $event['event_type']);
        $this->assertSame(PipelineJob::TYPE_SCRAPE, $event['job_type']);
        $this->assertDatabaseHas('pipeline_events', [
            'task_id' => 'task-event-monitor-timeline',
            'job_id' => 'scrape-event-monitor-timeline',
            'event_type' => PipelineEvent::SCRAPE_MONITOR_REQUESTED,
            'source' => 'rabbitmq.publish',
        ]);

        $record = PipelineEventRecord::query()
            ->where('task_id', 'task-event-monitor-timeline')
            ->where('event_type', PipelineEvent::SCRAPE_MONITOR_REQUESTED)
            ->firstOrFail();
        $this->assertSame('Scrape monitor requested: /app/shared/task-event-monitor-timeline/scrape-event-monitor-timeline', $record->message);
    }

    public function test_publishing_scrape_monitor_event_declares_worker_topology_before_publish(): void
    {
        config()->set('communication.rabbitmq.pipeline_events.enabled', true);

        $channel = \Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('exchange_declare')->zeroOrMoreTimes();
        $channel->shouldReceive('queue_declare')
            ->once()
            ->with('pipeline_scrape_monitor_events', false, true, false, false, false, \Mockery::any());
        $channel->shouldReceive('queue_bind')
            ->once()
            ->with('pipeline_scrape_monitor_events', 'pipeline.events', PipelineEvent::SCRAPE_MONITOR_REQUESTED);
        $channel->shouldReceive('queue_declare')
            ->once()
            ->with('pipeline_scrape_monitor_events.retry.scrape_monitor_requested', false, true, false, false, false, \Mockery::any());
        $channel->shouldReceive('queue_bind')
            ->once()
            ->with('pipeline_scrape_monitor_events.retry.scrape_monitor_requested', 'pipeline.retry', PipelineEvent::SCRAPE_MONITOR_REQUESTED);
        $channel->shouldReceive('queue_declare')
            ->once()
            ->with('pipeline_failed_events', false, true, false, false, false, \Mockery::any());
        $channel->shouldReceive('queue_bind')
            ->once()
            ->with('pipeline_failed_events', 'pipeline.failures', PipelineEvent::JOB_FAILED);
        $channel->shouldReceive('basic_publish')
            ->once()
            ->with(\Mockery::type(AMQPMessage::class), 'pipeline.events', PipelineEvent::SCRAPE_MONITOR_REQUESTED);

        $rabbit = \Mockery::mock(RagRabbitMQ::class);
        $rabbit->shouldReceive('channel')->zeroOrMoreTimes()->andReturn($channel);
        $this->app->instance(RagRabbitMQ::class, $rabbit);

        app(PipelineEventBus::class)->publish(PipelineEvent::SCRAPE_MONITOR_REQUESTED, [
            'task_id' => 'task-event-monitor-topology',
            'job_id' => 'scrape-event-monitor-topology',
            'source_url' => 'https://example.test/monitor-topology',
            'local_path' => '/app/shared/task-event-monitor-topology/scrape-event-monitor-topology',
            'status' => PipelineJob::STATUS_RUNNING,
        ]);
    }

    public function test_scraper_consumer_publishes_initial_rabbitmq_monitor_event_after_submit(): void
    {
        config()->set('scraper.storage_path', storage_path('framework/testing/pipeline-events/scrape-monitor-output'));

        $this->mock(ScraperPipelineService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('execute')
                ->once()
                ->andReturn(ScrapeRequestResult::success('scrape-event-monitor-start', 'submitted'));
        });

        $this->mock(PipelineEventBus::class, function (MockInterface $mock): void {
            $mock->shouldReceive('publish')
                ->once()
                ->with(PipelineEvent::SCRAPE_MONITOR_REQUESTED, \Mockery::on(
                    fn (array $event): bool => $event['job_id'] === 'scrape-event-monitor-start'
                        && str_ends_with((string) $event['local_path'], 'task-event-monitor-start/scrape-event-monitor-start')
                        && $event['status'] === PipelineJob::STATUS_RUNNING
                        && ($event['metadata']['monitor_mode'] ?? null) === 'rabbitmq'
                        && ($event['metadata']['monitor_attempt'] ?? null) === 0
                ))
                ->andReturnUsing(fn (string $eventType, array $payload): array => PipelineEvent::normalize($eventType, $payload));
        });

        $task = $this->task('task-event-monitor-start');
        app(ScraperEventHandler::class)->handle(PipelineEvent::normalize(PipelineEvent::SCRAPE_REQUESTED, [
            'task_id' => $task->task_id,
            'job_id' => 'scrape-event-monitor-start',
            'dataset_id' => $task->dataset_id,
            'source_url' => 'https://example.test/monitor-start',
            'status' => PipelineJob::STATUS_PENDING,
        ]));

        $this->assertDatabaseHas('pipeline_jobs', [
            'task_id' => $task->task_id,
            'job_id' => 'scrape-event-monitor-start',
            'status' => PipelineJob::STATUS_RUNNING,
        ]);
    }

    public function test_scrape_monitor_event_handler_reschedules_running_crawls_through_rabbitmq_delay(): void
    {
        $task = $this->task('task-event-monitor-probe');
        $datasetPath = storage_path('framework/testing/pipeline-events/monitor-running');
        PipelineJob::query()->create([
            'job_id' => 'scrape-event-monitor-probe',
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => 'https://example.test/monitor-probe',
            'local_path' => $datasetPath,
            'status' => PipelineJob::STATUS_RUNNING,
            'started_at' => now(),
            'metadata' => [
                'dataset_id' => $task->dataset_id,
            ],
        ]);

        $this->mock(ScrapeService::class, function (MockInterface $mock) use ($datasetPath): void {
            $mock->shouldReceive('getCrawlerStatus')
                ->once()
                ->with('scrape-event-monitor-probe')
                ->andReturn([
                    'success' => true,
                    'status' => 200,
                    'data' => [
                        'status' => 'running',
                        'output_directory' => $datasetPath,
                        'total_pages' => 3,
                        'pages_crawled' => 1,
                        'failed_urls' => 0,
                        'message' => 'Crawler still running.',
                    ],
                ]);
        });

        $this->mock(PipelineEventBus::class, function (MockInterface $mock) use ($task, $datasetPath): void {
            $mock->shouldReceive('publishDelayed')
                ->once()
                ->with(\Mockery::on(
                    fn (array $event): bool => $event['event_type'] === PipelineEvent::SCRAPE_MONITOR_REQUESTED
                        && $event['task_id'] === $task->task_id
                        && $event['job_id'] === 'scrape-event-monitor-probe'
                        && $event['local_path'] === $datasetPath
                        && ($event['metadata']['monitor_attempt'] ?? null) === 1
                ), 'Crawl is still running.')
                ->andReturnUsing(fn (array $event, string $reason): array => PipelineEvent::normalize((string) $event['event_type'], $event));
        });

        app(ScrapeMonitorEventHandler::class)->handle(PipelineEvent::normalize(PipelineEvent::SCRAPE_MONITOR_REQUESTED, [
            'task_id' => $task->task_id,
            'job_id' => 'scrape-event-monitor-probe',
            'dataset_id' => $task->dataset_id,
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => 'https://example.test/monitor-probe',
            'local_path' => $datasetPath,
            'status' => PipelineJob::STATUS_RUNNING,
        ]));

        $this->assertDatabaseHas('pipeline_jobs', [
            'task_id' => $task->task_id,
            'job_id' => 'scrape-event-monitor-probe',
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'status' => PipelineJob::STATUS_RUNNING,
        ]);

        $job = PipelineJob::query()->where('job_id', 'scrape-event-monitor-probe')->firstOrFail();
        $this->assertSame('scrape_monitor_running', $job->metadata['stage'] ?? null);
        $this->assertSame(1, $job->metadata['monitor_attempt'] ?? null);
        $this->assertDatabaseMissing('pipeline_events', [
            'task_id' => $task->task_id,
            'event_type' => PipelineEvent::PAGE_SCRAPED,
        ]);
        $this->assertDatabaseMissing('pipeline_events', [
            'task_id' => $task->task_id,
            'event_type' => PipelineEvent::FILE_DISCOVERED,
        ]);
    }

    public function test_scrape_monitor_event_handler_publishes_completion_and_file_events(): void
    {
        config()->set('file_converter.supported_extensions', ['pdf']);

        $task = $this->task('task-event-monitor-completed');
        $datasetPath = storage_path('framework/testing/pipeline-events/monitor-completed');
        File::ensureDirectoryExists($datasetPath);
        $pdf = "{$datasetPath}/download.pdf";
        File::put($pdf, '%PDF-1.4 monitor completion file');
        $contentHash = hash_file('sha256', $pdf);
        $convertJobId = 'convert_' . substr(hash('sha256', $task->task_id . '|' . $pdf), 0, 24);

        PipelineJob::query()->create([
            'job_id' => 'scrape-event-monitor-completed',
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => 'https://example.test/monitor-completed',
            'status' => PipelineJob::STATUS_RUNNING,
            'started_at' => now(),
            'metadata' => [
                'dataset_id' => $task->dataset_id,
            ],
        ]);

        $this->mock(ScrapeService::class, function (MockInterface $mock) use ($datasetPath): void {
            $mock->shouldReceive('getCrawlerStatus')
                ->once()
                ->with('scrape-event-monitor-completed')
                ->andReturn([
                    'success' => true,
                    'status' => 200,
                    'data' => [
                        'status' => 'completed',
                        'output_directory' => $datasetPath,
                        'total_pages' => 1,
                        'pages_crawled' => 1,
                        'failed_urls' => 0,
                    ],
                ]);
        });

        $this->mock(PipelineEventBus::class, function (MockInterface $mock) use ($task, $datasetPath, $pdf, $contentHash, $convertJobId): void {
            $mock->shouldReceive('publish')
                ->once()
                ->with(PipelineEvent::PAGE_SCRAPED, \Mockery::on(
                    fn (array $event): bool => $event['task_id'] === $task->task_id
                        && $event['job_id'] === 'scrape-event-monitor-completed'
                        && $event['local_path'] === $datasetPath
                        && $event['status'] === PipelineJob::STATUS_COMPLETED
                ))
                ->andReturnUsing(fn (string $eventType, array $payload): array => PipelineEvent::normalize($eventType, $payload));

            $mock->shouldReceive('publish')
                ->once()
                ->with(PipelineEvent::FILE_DISCOVERED, \Mockery::on(
                    fn (array $event): bool => $event['task_id'] === $task->task_id
                        && $event['job_id'] === $convertJobId
                        && $event['parent_job_id'] === 'scrape-event-monitor-completed'
                        && $event['local_path'] === $pdf
                        && $event['content_hash'] === $contentHash
                ))
                ->andReturnUsing(fn (string $eventType, array $payload): array => PipelineEvent::normalize($eventType, $payload));
        });

        app(ScrapeMonitorEventHandler::class)->handle(PipelineEvent::normalize(PipelineEvent::SCRAPE_MONITOR_REQUESTED, [
            'task_id' => $task->task_id,
            'job_id' => 'scrape-event-monitor-completed',
            'dataset_id' => $task->dataset_id,
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => 'https://example.test/monitor-completed',
            'status' => PipelineJob::STATUS_RUNNING,
        ]));

        $this->assertDatabaseHas('pipeline_jobs', [
            'task_id' => $task->task_id,
            'job_id' => 'scrape-event-monitor-completed',
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'status' => PipelineJob::STATUS_COMPLETED,
            'local_path' => $datasetPath,
        ]);
    }

    public function test_scrape_monitor_event_handler_publishes_failed_event_for_failed_crawls(): void
    {
        $task = $this->task('task-event-monitor-failed');
        PipelineJob::query()->create([
            'job_id' => 'scrape-event-monitor-failed',
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => 'https://example.test/monitor-failed',
            'status' => PipelineJob::STATUS_RUNNING,
            'started_at' => now(),
            'metadata' => [
                'dataset_id' => $task->dataset_id,
            ],
        ]);

        $this->mock(ScrapeService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getCrawlerStatus')
                ->once()
                ->with('scrape-event-monitor-failed')
                ->andReturn([
                    'success' => true,
                    'status' => 200,
                    'data' => [
                        'status' => 'failed',
                        'message' => 'Crawler crashed.',
                        'total_pages' => 2,
                        'pages_crawled' => 1,
                        'failed_urls' => 1,
                    ],
                ]);
        });

        $this->mock(PipelineEventBus::class, function (MockInterface $mock) use ($task): void {
            $mock->shouldReceive('publishFailed')
                ->once()
                ->with(\Mockery::on(
                    fn (array $event): bool => $event['task_id'] === $task->task_id
                        && $event['job_id'] === 'scrape-event-monitor-failed'
                        && $event['status'] === PipelineJob::STATUS_FAILED
                        && ($event['metadata']['error_message'] ?? null) === 'Crawler crashed.'
                ), \Mockery::type(\RuntimeException::class))
                ->andReturnUsing(fn (array $event, \RuntimeException $error): array => PipelineEvent::normalize(PipelineEvent::JOB_FAILED, array_merge($event, [
                    'metadata' => array_merge($event['metadata'] ?? [], [
                        'error_message' => $error->getMessage(),
                    ]),
                ])));
        });

        app(ScrapeMonitorEventHandler::class)->handle(PipelineEvent::normalize(PipelineEvent::SCRAPE_MONITOR_REQUESTED, [
            'task_id' => $task->task_id,
            'job_id' => 'scrape-event-monitor-failed',
            'dataset_id' => $task->dataset_id,
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => 'https://example.test/monitor-failed',
            'status' => PipelineJob::STATUS_RUNNING,
        ]));

        $this->assertDatabaseHas('pipeline_jobs', [
            'task_id' => $task->task_id,
            'job_id' => 'scrape-event-monitor-failed',
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'status' => PipelineJob::STATUS_FAILED,
            'error_message' => 'Crawler crashed.',
        ]);
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

    public function test_converter_consumer_records_database_duplicate_conversions_as_skipped_jobs(): void
    {
        $this->mock(DocumentConverter::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('requestDocumentToMarkdown');
        });

        $task = $this->task('task-event-convert-db-skip');
        $root = storage_path('framework/testing/pipeline-events/convert-db-skip');
        $sourceFile = "{$root}/handbook.pdf";
        File::ensureDirectoryExists($root);
        File::put($sourceFile, '%PDF-1.4 fake test file');
        $contentHash = hash_file('sha256', $sourceFile);

        PipelineJob::query()->create([
            'job_id' => 'convert-event-previous',
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_CONVERT,
            'source_url' => 'https://example.test/handbook.pdf',
            'local_path' => $sourceFile,
            'content_hash' => $contentHash,
            'status' => PipelineJob::STATUS_COMPLETED,
            'started_at' => now(),
            'finished_at' => now(),
            'metadata' => [],
        ]);

        app(ConverterEventHandler::class)->handle(PipelineEvent::normalize(PipelineEvent::FILE_DISCOVERED, [
            'task_id' => $task->task_id,
            'job_id' => 'convert-event-db-skip',
            'parent_job_id' => 'scrape-parent',
            'dataset_id' => $task->dataset_id,
            'source_url' => 'https://example.test/handbook.pdf',
            'local_path' => $sourceFile,
            'content_hash' => $contentHash,
            'status' => PipelineJob::STATUS_PENDING,
        ]));

        $this->assertDatabaseHas('pipeline_jobs', [
            'task_id' => $task->task_id,
            'job_id' => 'convert-event-db-skip',
            'job_type' => PipelineJob::TYPE_CONVERT,
            'local_path' => $sourceFile,
            'status' => PipelineJob::STATUS_SKIPPED,
        ]);
        $this->assertDatabaseHas('pipeline_events', [
            'task_id' => $task->task_id,
            'job_id' => 'convert-event-db-skip',
            'event_type' => PipelineEvent::FILE_CONVERTED,
            'source' => 'rabbitmq.publish',
        ]);

        $task->refresh();
        $this->assertSame(2, $task->counters['convert_jobs']);
        $this->assertSame(1, $task->counters['converted']);
        $this->assertSame(1, $task->counters['skipped']);
    }

    public function test_converter_consumer_combines_all_markdown_chunks_for_ingestion(): void
    {
        $task = $this->task('task-event-convert-chunks');
        $root = storage_path('framework/testing/pipeline-events/convert-chunks');
        $sourceFile = "{$root}/handbook.pdf";
        File::ensureDirectoryExists($root);
        File::put($sourceFile, '%PDF-1.4 fake chunked file');
        $contentHash = hash_file('sha256', $sourceFile);

        $this->mock(DocumentConverter::class, function (MockInterface $mock): void {
            $mock->shouldReceive('requestDocumentToMarkdown')
                ->once()
                ->andReturn([
                    'output/meta.json' => '{"chunk_count":2}',
                    'output/chunks/00002.md' => "---\nchunk: 2\n---\n\nSecond chunk.",
                    'output/chunks/00001.md' => "---\nchunk: 1\n---\n\nFirst chunk.",
                    'output/assets/image_0.webp' => 'fake-binary-webp',
                ]);
        });

        $this->mock(PipelineEventBus::class, function (MockInterface $mock) use ($task, $sourceFile): void {
            $mock->shouldReceive('publish')
                ->once()
                ->with(PipelineEvent::FILE_CONVERTED, \Mockery::on(function (array $event) use ($task, $sourceFile): bool {
                    $path = (string) ($event['local_path'] ?? '');
                    $content = is_file($path) ? (string) file_get_contents($path) : '';

                    return $event['task_id'] === $task->task_id
                        && $event['job_id'] === 'convert-event-chunks'
                        && ($event['metadata']['original_path'] ?? null) === $sourceFile
                        && str_ends_with($path, 'converted_handbook/content_markdown.md')
                        && str_contains($content, 'First chunk.')
                        && str_contains($content, 'Second chunk.')
                        && strpos($content, 'First chunk.') < strpos($content, 'Second chunk.');
                }))
                ->andReturnUsing(fn (string $eventType, array $payload): array => PipelineEvent::normalize($eventType, $payload));
        });

        app(ConverterEventHandler::class)->handle(PipelineEvent::normalize(PipelineEvent::FILE_DISCOVERED, [
            'task_id' => $task->task_id,
            'job_id' => 'convert-event-chunks',
            'parent_job_id' => 'scrape-parent',
            'dataset_id' => $task->dataset_id,
            'source_url' => 'https://example.test/handbook.pdf',
            'local_path' => $sourceFile,
            'content_hash' => $contentHash,
            'status' => PipelineJob::STATUS_PENDING,
        ]));

        $outputDir = "{$root}/converted_handbook";
        $combinedPath = "{$outputDir}/content_markdown.md";
        $this->assertFileExists($combinedPath);
        $this->assertFileExists("{$outputDir}/output/meta.json");
        $this->assertFileExists("{$outputDir}/output/chunks/00001.md");
        $this->assertFileExists("{$outputDir}/output/chunks/00002.md");
        $this->assertFileExists("{$outputDir}/output/assets/image_0.webp");

        $meta = json_decode((string) file_get_contents("{$outputDir}/conversion_meta.json"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($combinedPath, $meta['combined_markdown_path']);
        $this->assertSame([
            'output/chunks/00001.md',
            'output/chunks/00002.md',
        ], $meta['markdown_files']);

        $this->assertDatabaseHas('pipeline_jobs', [
            'task_id' => $task->task_id,
            'job_id' => 'convert-event-chunks',
            'job_type' => PipelineJob::TYPE_CONVERT,
            'status' => PipelineJob::STATUS_COMPLETED,
            'local_path' => $sourceFile,
        ]);
    }

    public function test_ingestion_consumer_ingests_scraped_content_and_records_content_ingestion(): void
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

        Http::assertSent(fn ($request) => $request->url() === 'http://hawki_rag_bridge:8000/ingest'
            && $request['docs'][0]['payload']['task_id'] === $task->task_id);

        $task->refresh();
        $this->assertSame(1, $task->counters['ingested']);
        $this->assertSame(0, $task->counters['failed']);
    }

    public function test_ingestion_consumer_ingests_cached_converter_markdown_even_when_converter_job_was_skipped(): void
    {
        Http::fake([
            '*/ingest' => Http::response(['ok' => true, 'documents' => 1], 200),
        ]);

        $task = $this->task('task-event-ingest-cached-conversion');
        $root = storage_path('framework/testing/pipeline-events/ingest-cached-conversion/converted_upload');
        $markdownPath = "{$root}/content_markdown.md";
        File::ensureDirectoryExists($root);
        File::put($markdownPath, "# Cached conversion\n\nMarkdown created by a previous converter run.");

        app(IngestionEventHandler::class)->handle(PipelineEvent::normalize(PipelineEvent::FILE_CONVERTED, [
            'task_id' => $task->task_id,
            'job_id' => 'convert-event-cached',
            'dataset_id' => $task->dataset_id,
            'job_type' => PipelineJob::TYPE_CONVERT,
            'source_url' => 'upload://resume.pdf',
            'local_path' => $markdownPath,
            'content_hash' => hash_file('sha256', $markdownPath),
            'status' => PipelineJob::STATUS_SKIPPED,
            'metadata' => [
                'reason' => 'File/content_hash was already converted.',
                'converted_path' => $markdownPath,
                'graph' => true,
            ],
        ]));

        $ingestJob = PipelineJob::query()
            ->where('task_id', $task->task_id)
            ->where('job_type', PipelineJob::TYPE_INGEST)
            ->firstOrFail();

        $this->assertSame(PipelineJob::STATUS_COMPLETED, $ingestJob->status);
        $this->assertSame('convert-event-cached', $ingestJob->parent_job_id);
        $this->assertSame($markdownPath, $ingestJob->local_path);

        Http::assertSent(fn ($request) => $request->url() === 'http://hawki_rag_bridge:8000/ingest'
            && $request['docs'][0]['payload']['converted_path'] === $markdownPath
            && $request['docs'][0]['payload']['source_type'] === PipelineEvent::FILE_CONVERTED);

        $task->refresh();
        $this->assertSame(1, $task->counters['ingested']);
        $this->assertSame(0, $task->counters['skipped']);
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
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => 'https://example.test/page',
            'local_path' => $markdownPath,
            'content_hash' => hash_file('sha256', $markdownPath),
            'status' => PipelineJob::STATUS_COMPLETED,
        ]), new \RuntimeException('Bridge unavailable'), 3, 3);

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
        $failed = app(PipelineEventBus::class)->publishFailed(PipelineEvent::normalize(PipelineEvent::FILE_DISCOVERED, [
            'task_id' => 'task-event-failed',
            'job_id' => 'convert-event-failed',
            'parent_job_id' => 'scrape-parent',
            'dataset_id' => 'dataset-events',
            'job_type' => PipelineJob::TYPE_CONVERT,
            'source_url' => 'https://example.test/file.pdf',
            'local_path' => '/app/shared/file.pdf',
            'content_hash' => 'sha256-file',
        ]), new \RuntimeException('Conversion failed'));

        foreach (PipelineEvent::REQUIRED_PAYLOAD_FIELDS as $field) {
            $this->assertArrayHasKey($field, $failed);
        }

        $this->assertSame(PipelineEvent::JOB_FAILED, $failed['event_type']);
        $this->assertSame('task-event-failed', $failed['task_id']);
        $this->assertSame('convert-event-failed', $failed['job_id']);
        $this->assertSame(PipelineJob::TYPE_CONVERT, $failed['job_type']);
        $this->assertSame(PipelineEvent::FILE_DISCOVERED, $failed['metadata']['original_event_type']);

        $this->assertDatabaseHas('pipeline_events', [
            'task_id' => 'task-event-failed',
            'job_id' => 'convert-event-failed',
            'event_type' => PipelineEvent::JOB_FAILED,
            'source' => 'rabbitmq.failed',
        ]);
        $record = PipelineEventRecord::query()
            ->where('task_id', 'task-event-failed')
            ->where('job_id', 'convert-event-failed')
            ->where('event_type', PipelineEvent::JOB_FAILED)
            ->firstOrFail();
        $this->assertSame('Job failed: Conversion failed', $record->message);
    }

    public function test_published_pipeline_events_are_recorded_for_timeline_even_when_rabbitmq_is_disabled(): void
    {
        $event = app(PipelineEventBus::class)->publish(PipelineEvent::SCRAPE_REQUESTED, [
            'task_id' => 'task-event-timeline',
            'job_id' => 'scrape-event-timeline',
            'source_url' => 'https://example.test/timeline',
            'status' => PipelineJob::STATUS_QUEUED,
        ]);

        $this->assertSame(PipelineEvent::SCRAPE_REQUESTED, $event['event_type']);
        $this->assertDatabaseHas('pipeline_events', [
            'task_id' => 'task-event-timeline',
            'job_id' => 'scrape-event-timeline',
            'event_type' => PipelineEvent::SCRAPE_REQUESTED,
            'source' => 'rabbitmq.publish',
        ]);

        $record = PipelineEventRecord::query()
            ->where('task_id', 'task-event-timeline')
            ->firstOrFail();
        $this->assertSame('URL queued: https://example.test/timeline', $record->message);
        $this->assertSame(PipelineJob::STATUS_QUEUED, $record->payload['status'] ?? null);
    }

    private function task(string $taskId): PipelineTask
    {
        return PipelineTask::query()->create([
            'task_id' => $taskId,
            'dataset_id' => 'dataset-events',
            'status' => PipelineTask::STATUS_RUNNING,
            'started_at' => now(),
            'counters' => [],
            'metadata' => [],
        ]);
    }
}
