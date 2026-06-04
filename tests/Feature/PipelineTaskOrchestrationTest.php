<?php

namespace Tests\Feature;

use App\Models\PipelineJob;
use App\Models\PipelineEventRecord;
use App\Models\PipelineTask;
use App\Models\ScrapedElement;
use App\Services\Pipeline\PipelineEvent;
use App\Services\Pipeline\PipelineEventBus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class PipelineTaskOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_orchestrated_task_creates_task_and_child_jobs_with_skips(): void
    {
        config()->set('communication.rabbitmq.pipeline_events.enabled', false);

        $alreadyScrapedUrl = 'https://already.example/page';
        ScrapedElement::query()->create([
            'uuid' => (string) Str::uuid(),
            'title' => 'Already scraped',
            'page_url' => $alreadyScrapedUrl,
            'page_url_hash' => hash('sha256', $alreadyScrapedUrl),
            'content_hash' => hash('sha256', $alreadyScrapedUrl),
            'job_id' => 'existing-scrape-job',
        ]);

        $this->postJson('/api/pipeline/tasks/start', [
            'task_id' => 'task-test-1',
            'dataset_id' => 'dataset-hawk',
            'urls' => [
                $alreadyScrapedUrl,
                'https://new.example/page',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('taskId', 'task-test-1')
            ->assertJsonPath('task.counters.jobs_total', 2)
            ->assertJsonPath('task.counters.jobs_skipped', 1)
            ->assertJsonPath('task.counters.queued', 1);

        $this->assertDatabaseHas('pipeline_tasks', [
            'task_id' => 'task-test-1',
            'dataset_id' => 'dataset-hawk',
            'status' => PipelineTask::STATUS_RUNNING,
        ]);

        $this->assertDatabaseHas('pipeline_jobs', [
            'task_id' => 'task-test-1',
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => $alreadyScrapedUrl,
            'status' => PipelineJob::STATUS_SKIPPED,
        ]);

        $this->assertDatabaseHas('pipeline_jobs', [
            'task_id' => 'task-test-1',
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => 'https://new.example/page',
            'status' => PipelineJob::STATUS_PENDING,
        ]);
    }

    public function test_task_completion_is_calculated_after_job_updates(): void
    {
        config()->set('communication.rabbitmq.pipeline_events.enabled', false);

        $this->postJson('/api/pipeline/tasks/start', [
            'task_id' => 'task-test-2',
            'urls' => ['https://new.example/page'],
        ])->assertCreated();

        $task = $this->getJson('/api/pipeline/tasks/task-test-2')
            ->assertOk()
            ->assertJsonPath('task.status', PipelineTask::STATUS_RUNNING)
            ->assertJsonPath('task.activeJobs', 1)
            ->json('task');

        $job = PipelineJob::query()
            ->where('task_id', 'task-test-2')
            ->firstOrFail();

        $this->postJson('/api/pipeline/tasks/task-test-2/jobs', [
            'job_id' => $job->job_id,
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => $job->source_url,
            'status' => PipelineJob::STATUS_COMPLETED,
        ])->assertOk();

        $this->getJson('/api/pipeline/tasks/task-test-2')
            ->assertOk()
            ->assertJsonPath('task.status', PipelineTask::STATUS_COMPLETED)
            ->assertJsonPath('task.activeJobs', 0);

        $this->assertSame('task-test-2', $task['taskId']);
    }

    public function test_pipeline_tasks_can_be_listed_for_playground_selection(): void
    {
        $older = PipelineTask::query()->create([
            'task_id' => 'task-list-old',
            'dataset_id' => 'dataset-old',
            'status' => PipelineTask::STATUS_COMPLETED,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(50),
            'counters' => [],
            'metadata' => [],
        ]);

        $newer = PipelineTask::query()->create([
            'task_id' => 'task-list-new',
            'dataset_id' => 'dataset-new',
            'status' => PipelineTask::STATUS_RUNNING,
            'started_at' => now(),
            'counters' => [],
            'metadata' => [
                'request' => [
                    'metadata' => [
                        'catalog_task_label' => 'New catalog task',
                    ],
                ],
            ],
        ]);

        PipelineJob::query()->create([
            'job_id' => 'scrape-list-new',
            'task_id' => $newer->task_id,
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'status' => PipelineJob::STATUS_PENDING,
            'started_at' => now(),
        ]);

        $this->getJson('/api/pipeline/tasks?limit=1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'tasks')
            ->assertJsonPath('tasks.0.taskId', 'task-list-new')
            ->assertJsonPath('tasks.0.datasetId', 'dataset-new')
            ->assertJsonPath('tasks.0.counters.jobs_total', 1)
            ->assertJsonPath('tasks.0.activeJobs', 1);

        $this->assertDatabaseHas('pipeline_tasks', [
            'task_id' => $older->task_id,
        ]);
    }

    public function test_task_start_metadata_is_copied_to_scrape_jobs(): void
    {
        config()->set('communication.rabbitmq.pipeline_events.enabled', false);

        $this->postJson('/api/pipeline/tasks/start', [
            'task_id' => 'task-test-metadata-options',
            'urls' => ['https://www.uni-goettingen.de'],
            'metadata' => [
                'source' => 'scraper-task-ui',
                'catalog_task_id' => 'manual-site-goettingen',
                'max_pages' => 25,
                'max_concurrency' => 2,
                'max_rpm' => 30,
                'skip_images' => true,
                'discovery_mode' => true,
            ],
        ])->assertCreated();

        $job = PipelineJob::query()
            ->where('task_id', 'task-test-metadata-options')
            ->where('job_type', PipelineJob::TYPE_SCRAPE)
            ->firstOrFail();

        $this->assertSame('scraper-task-ui', $job->metadata['source'] ?? null);
        $this->assertSame('manual-site-goettingen', $job->metadata['catalog_task_id'] ?? null);
        $this->assertSame(25, $job->metadata['max_pages'] ?? null);
        $this->assertSame(2, $job->metadata['max_concurrency'] ?? null);
        $this->assertSame(30, $job->metadata['max_rpm'] ?? null);
        $this->assertTrue($job->metadata['skip_images'] ?? false);
        $this->assertTrue($job->metadata['discovery_mode'] ?? false);
    }

    public function test_starting_task_publishes_scrape_requested_events(): void
    {
        $this->mock(PipelineEventBus::class, function ($mock): void {
            $mock->shouldReceive('publish')
                ->once()
                ->with(PipelineEvent::SCRAPE_REQUESTED, Mockery::on(
                    fn (array $payload): bool => $payload['task_id'] === 'task-test-publish'
                        && $payload['job_type'] === PipelineJob::TYPE_SCRAPE
                        && $payload['source_url'] === 'https://new.example/page'
                        && $payload['status'] === PipelineJob::STATUS_QUEUED
                ))
                ->andReturnUsing(fn (string $eventType, array $payload): array => PipelineEvent::normalize($eventType, $payload));
        });

        $this->postJson('/api/pipeline/tasks/start', [
            'task_id' => 'task-test-publish',
            'urls' => ['https://new.example/page'],
        ])->assertCreated();
    }

    public function test_dashboard_endpoints_show_failed_jobs_and_recent_events(): void
    {
        $this->withoutVite();

        PipelineTask::query()->create([
            'task_id' => 'task-dashboard',
            'dataset_id' => 'dataset-dashboard',
            'status' => PipelineTask::STATUS_FAILED,
            'started_at' => now()->subMinutes(4),
            'finished_at' => now(),
            'counters' => [],
            'metadata' => [],
        ]);

        $failedAt = now()->subMinute();

        PipelineJob::query()->create([
            'job_id' => 'convert-dashboard-failed',
            'task_id' => 'task-dashboard',
            'job_type' => PipelineJob::TYPE_CONVERT,
            'source_url' => 'https://www.hawk.de/file.pdf',
            'local_path' => '/app/shared/demo/file.pdf',
            'status' => PipelineJob::STATUS_FAILED,
            'error_message' => 'Converter failed',
            'started_at' => now()->subMinutes(3),
            'finished_at' => now()->subMinute(),
            'metadata' => [
                'events' => [[
                    'event_type' => PipelineEvent::JOB_FAILED,
                    'event_id' => 'event-dashboard-failed',
                    'status' => PipelineJob::STATUS_FAILED,
                    'at' => $failedAt->toIso8601String(),
                ]],
            ],
        ]);

        PipelineJob::query()->create([
            'job_id' => 'scrape-dashboard-completed',
            'task_id' => 'task-dashboard',
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => 'https://www.hawk.de/de',
            'status' => PipelineJob::STATUS_COMPLETED,
            'started_at' => now()->subMinutes(4),
            'finished_at' => now()->subMinutes(2),
            'metadata' => [],
        ]);

        PipelineEventRecord::query()->create([
            'task_id' => 'task-dashboard',
            'job_id' => 'scrape-dashboard-completed',
            'event_type' => PipelineEvent::PAGE_SCRAPED,
            'source' => 'test',
            'message' => 'Page scraped: https://www.hawk.de/de',
            'payload' => PipelineEvent::normalize(PipelineEvent::PAGE_SCRAPED, [
                'task_id' => 'task-dashboard',
                'job_id' => 'scrape-dashboard-completed',
                'source_url' => 'https://www.hawk.de/de',
                'status' => PipelineJob::STATUS_COMPLETED,
            ]),
            'created_at' => now()->subMinutes(2),
        ]);
        PipelineEventRecord::query()->create([
            'task_id' => 'task-dashboard',
            'job_id' => 'convert-dashboard-failed',
            'event_type' => PipelineEvent::JOB_FAILED,
            'source' => 'test',
            'message' => 'Job failed: Converter failed',
            'payload' => PipelineEvent::normalize(PipelineEvent::JOB_FAILED, [
                'task_id' => 'task-dashboard',
                'job_id' => 'convert-dashboard-failed',
                'job_type' => PipelineJob::TYPE_CONVERT,
                'source_url' => 'https://www.hawk.de/file.pdf',
                'local_path' => '/app/shared/demo/file.pdf',
                'status' => PipelineJob::STATUS_FAILED,
                'metadata' => [
                    'error_message' => 'Converter failed',
                ],
            ]),
            'created_at' => $failedAt,
        ]);

        $this->get('/pipeline-dashboard')
            ->assertOk()
            ->assertSee('HAWKI Pipeline Dashboard');

        $this->getJson('/api/pipeline/tasks/task-dashboard/failed-jobs')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'jobs')
            ->assertJsonPath('jobs.0.jobId', 'convert-dashboard-failed')
            ->assertJsonPath('jobs.0.jobType', PipelineJob::TYPE_CONVERT)
            ->assertJsonPath('jobs.0.status', PipelineJob::STATUS_FAILED)
            ->assertJsonPath('jobs.0.sourceUrl', 'https://www.hawk.de/file.pdf')
            ->assertJsonPath('jobs.0.errorMessage', 'Converter failed');

        $this->getJson('/api/pipeline/tasks/task-dashboard/events?limit=5')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('filters.eventTypes.0', PipelineEvent::JOB_FAILED)
            ->assertJsonPath('events.0.eventType', PipelineEvent::PAGE_SCRAPED)
            ->assertJsonFragment([
                'eventType' => PipelineEvent::JOB_FAILED,
                'jobId' => 'convert-dashboard-failed',
                'status' => PipelineJob::STATUS_FAILED,
                'errorMessage' => 'Converter failed',
            ]);

        $this->getJson('/api/pipeline/tasks/task-dashboard/events?event_type=' . PipelineEvent::JOB_FAILED)
            ->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.eventType', PipelineEvent::JOB_FAILED);

        $this->getJson('/api/pipeline/tasks/task-dashboard/events?job_id=scrape-dashboard-completed')
            ->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.jobId', 'scrape-dashboard-completed');
    }

    public function test_demo_pipeline_command_creates_visible_task_and_publishes_scrape_events(): void
    {
        $this->mock(PipelineEventBus::class, function ($mock): void {
            $mock->shouldReceive('publish')
                ->twice()
                ->with(PipelineEvent::SCRAPE_REQUESTED, Mockery::on(
                    fn (array $payload): bool => $payload['task_id'] !== ''
                        && $payload['dataset_id'] === 'demo-test'
                        && $payload['job_type'] === PipelineJob::TYPE_SCRAPE
                        && $payload['status'] === PipelineJob::STATUS_QUEUED
                        && ($payload['metadata']['graph'] ?? null) === false
                ))
                ->andReturnUsing(fn (string $eventType, array $payload): array => PipelineEvent::normalize($eventType, $payload));
        });

        $exitCode = Artisan::call('pipeline:demo', [
            '--dataset' => 'demo-test',
            '--limit' => '2',
            '--graph' => 'false',
            '--url' => [
                'https://demo.example/a',
                'https://demo.example/b',
                'https://demo.example/c',
            ],
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Created demo pipeline task.', $output);
        $this->assertStringContainsString('Dashboard URL:', $output);
        $this->assertStringContainsString('RabbitMQ events requested: 2 scrape.requested event(s).', $output);
        $this->assertStringContainsString('php artisan pipeline:scraper-event-worker', $output);

        $task = PipelineTask::query()->where('dataset_id', 'demo-test')->firstOrFail();
        $this->assertStringStartsWith('demo_', $task->task_id);
        $this->assertDatabaseHas('pipeline_tasks', [
            'task_id' => $task->task_id,
            'dataset_id' => 'demo-test',
            'status' => PipelineTask::STATUS_RUNNING,
        ]);

        $this->assertSame(2, PipelineJob::query()->where('task_id', $task->task_id)->count());
        $this->assertDatabaseHas('pipeline_jobs', [
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => 'https://demo.example/a',
            'status' => PipelineJob::STATUS_QUEUED,
        ]);
        $this->assertDatabaseHas('pipeline_jobs', [
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => 'https://demo.example/b',
            'status' => PipelineJob::STATUS_QUEUED,
        ]);
    }

    public function test_demo_pipeline_command_dry_run_does_not_create_jobs_or_publish_events(): void
    {
        $taskCount = PipelineTask::query()->count();
        $jobCount = PipelineJob::query()->count();

        $this->mock(PipelineEventBus::class, function ($mock): void {
            $mock->shouldNotReceive('publish');
        });

        $exitCode = Artisan::call('pipeline:demo', [
            '--dataset' => 'demo-dry-run',
            '--limit' => '2',
            '--dry-run' => 'true',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Dry run only.', Artisan::output());
        $this->assertSame($taskCount, PipelineTask::query()->count());
        $this->assertSame($jobCount, PipelineJob::query()->count());
    }

    public function test_pipeline_profile_routes_are_removed(): void
    {
        $this->get('/pipeline-profiles')->assertNotFound();
        $this->getJson('/api/pipeline/profiles')->assertNotFound();
    }

    public function test_demo_pipeline_command_is_disabled_in_production_without_force(): void
    {
        $taskCount = PipelineTask::query()->count();
        $jobCount = PipelineJob::query()->count();

        app()->detectEnvironment(fn (): string => 'production');

        $this->mock(PipelineEventBus::class, function ($mock): void {
            $mock->shouldNotReceive('publish');
        });

        $exitCode = Artisan::call('pipeline:demo', [
            '--dataset' => 'demo-production',
            '--limit' => '1',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('pipeline:demo is disabled in production.', Artisan::output());
        $this->assertSame($taskCount, PipelineTask::query()->count());
        $this->assertSame($jobCount, PipelineJob::query()->count());
    }

    public function test_failed_jobs_can_be_retried_without_requeueing_completed_jobs(): void
    {
        config()->set('communication.rabbitmq.pipeline_events.enabled', false);

        $this->postJson('/api/pipeline/tasks/start', [
            'task_id' => 'task-test-3',
            'urls' => ['https://new.example/page'],
        ])->assertCreated();

        $job = PipelineJob::query()
            ->where('task_id', 'task-test-3')
            ->firstOrFail();

        $this->postJson('/api/pipeline/tasks/task-test-3/jobs', [
            'job_id' => $job->job_id,
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => $job->source_url,
            'status' => PipelineJob::STATUS_FAILED,
            'error_message' => 'Crawler failed',
        ])->assertOk();

        $this->getJson('/api/pipeline/tasks/task-test-3')
            ->assertOk()
            ->assertJsonPath('task.status', PipelineTask::STATUS_FAILED)
            ->assertJsonPath('task.counters.failed', 1);

        $this->postJson('/api/pipeline/tasks/task-test-3/retry-failed-jobs')
            ->assertOk()
            ->assertJsonPath('task.status', PipelineTask::STATUS_RUNNING)
            ->assertJsonPath('task.counters.queued', 1);

        $this->assertDatabaseHas('pipeline_jobs', [
            'job_id' => $job->job_id,
            'status' => PipelineJob::STATUS_QUEUED,
            'error_message' => null,
        ]);
    }
}
