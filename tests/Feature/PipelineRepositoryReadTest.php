<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Models\ScrapedElement;
use App\Services\Pipeline\Repositories\PipelineJobRepository;
use App\Services\Pipeline\Repositories\PipelineScrapeHistoryRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class PipelineRepositoryReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_repository_finds_task_by_task_id(): void
    {
        $task = $this->task('task-find-by-id');
        $repository = app(PipelineTaskRepository::class);

        $this->assertSame($task->task_id, $repository->findByTaskId('task-find-by-id')?->task_id);
        $this->assertSame($task->task_id, $repository->findByTaskIdOrFail('task-find-by-id')->task_id);
        $this->assertNull($repository->findByTaskId('missing-task'));

        $this->expectException(ModelNotFoundException::class);
        $repository->findByTaskIdOrFail('missing-task');
    }

    public function test_task_repository_reads_recent_tasks_with_limit_and_ordering(): void
    {
        $this->task('task-recent-old', Carbon::parse('2026-06-08 11:00:00'));
        $firstRecent = $this->task('task-recent-first', Carbon::parse('2026-06-08 12:00:00'));
        $secondRecent = $this->task('task-recent-second', Carbon::parse('2026-06-08 12:00:00'));

        $tasks = app(PipelineTaskRepository::class)->recent(2);

        $this->assertSame(
            [$secondRecent->task_id, $firstRecent->task_id],
            $tasks->pluck('task_id')->all(),
        );
    }

    public function test_task_repository_loads_task_with_jobs_ordered_by_id(): void
    {
        $task = $this->task();
        $first = $this->job($task, 'job-first', PipelineJob::STATUS_QUEUED);
        $second = $this->job($task, 'job-second', PipelineJob::STATUS_COMPLETED);

        $loaded = app(PipelineTaskRepository::class)->findWithOrderedJobs($task->task_id);

        $this->assertNotNull($loaded);
        $this->assertSame($task->task_id, $loaded->task_id);
        $this->assertTrue($loaded->relationLoaded('jobs'));
        $this->assertSame([$first->job_id, $second->job_id], $loaded->jobs->pluck('job_id')->all());
    }

    public function test_task_repository_creates_running_tasks(): void
    {
        $dataset = $this->dataset('repository-create-task-dataset');
        $startedAt = Carbon::parse('2026-06-08 12:30:00');
        $counters = [
            'queued' => 0,
            'jobs_total' => 0,
        ];
        $metadata = [
            'orchestration' => 'laravel',
            'request' => ['urls' => ['https://example.test/page']],
        ];

        $task = app(PipelineTaskRepository::class)->createRunningTask(
            'task-create-running',
            $dataset,
            $startedAt,
            $counters,
            $metadata,
        );

        $this->assertSame('task-create-running', $task->task_id);
        $this->assertSame($dataset->dataset_id, $task->dataset_id);
        $this->assertSame(PipelineTask::STATUS_RUNNING, $task->status);
        $this->assertTrue($startedAt->equalTo($task->started_at));
        $this->assertSame($counters, $task->counters);
        $this->assertSame($metadata, $task->metadata);

        $this->assertDatabaseHas('pipeline_tasks', [
            'task_id' => 'task-create-running',
            'dataset_id' => 'repository-create-task-dataset',
            'status' => PipelineTask::STATUS_RUNNING,
        ]);
    }

    public function test_task_repository_updates_status_finished_at_and_counters(): void
    {
        $task = $this->task('task-status-counters');
        $finishedAt = Carbon::parse('2026-06-08 13:00:00');
        $counters = [
            'jobs_total' => 3,
            'jobs_completed' => 2,
            'jobs_failed' => 1,
        ];

        $updated = app(PipelineTaskRepository::class)->updateStatusCounters(
            $task,
            PipelineTask::STATUS_FAILED,
            $finishedAt,
            $counters,
        );

        $this->assertSame(PipelineTask::STATUS_FAILED, $updated->status);
        $this->assertTrue($finishedAt->equalTo($updated->finished_at));
        $this->assertSame($counters, $updated->counters);

        $this->assertDatabaseHas('pipeline_tasks', [
            'task_id' => 'task-status-counters',
            'status' => PipelineTask::STATUS_FAILED,
        ]);
    }

    public function test_task_repository_marks_failed_jobs_retried(): void
    {
        $task = $this->task('task-failed-jobs-retried');
        $task->forceFill([
            'status' => PipelineTask::STATUS_FAILED,
            'finished_at' => Carbon::parse('2026-06-08 13:30:00'),
            'metadata' => [
                'events' => [
                    ['event' => 'task_failed', 'at' => '2026-06-08T13:30:00+00:00'],
                ],
            ],
        ])->save();

        $metadata = [
            'events' => [
                ['event' => 'task_failed', 'at' => '2026-06-08T13:30:00+00:00'],
                ['event' => 'failed_jobs_retried', 'at' => '2026-06-08T14:00:00+00:00'],
            ],
        ];

        $updated = app(PipelineTaskRepository::class)->markFailedJobsRetried($task, $metadata);

        $this->assertSame(PipelineTask::STATUS_RUNNING, $updated->status);
        $this->assertNull($updated->finished_at);
        $this->assertSame($metadata, $updated->metadata);

        $this->assertDatabaseHas('pipeline_tasks', [
            'task_id' => 'task-failed-jobs-retried',
            'status' => PipelineTask::STATUS_RUNNING,
            'finished_at' => null,
        ]);
    }

    public function test_job_repository_reads_ordered_failed_and_active_jobs(): void
    {
        $task = $this->task();
        $queued = $this->job($task, 'job-queued', PipelineJob::STATUS_QUEUED);
        $running = $this->job($task, 'job-running', PipelineJob::STATUS_RUNNING);
        $olderFailed = $this->job($task, 'job-failed-old', PipelineJob::STATUS_FAILED);
        $newerFailed = $this->job($task, 'job-failed-new', PipelineJob::STATUS_FAILED);
        $this->job($task, 'job-completed', PipelineJob::STATUS_COMPLETED);

        $olderFailed->forceFill(['updated_at' => Carbon::parse('2026-06-08 12:00:00')])->save();
        $newerFailed->forceFill(['updated_at' => Carbon::parse('2026-06-08 12:05:00')])->save();

        $repository = app(PipelineJobRepository::class);

        $this->assertEqualsCanonicalizing(
            ['job-queued', 'job-running', 'job-failed-old', 'job-failed-new', 'job-completed'],
            $repository->forTask($task->task_id)->pluck('job_id')->all(),
        );
        $this->assertSame(
            ['job-queued', 'job-running', 'job-failed-old', 'job-failed-new', 'job-completed'],
            $repository->forTaskOrdered($task->task_id)->pluck('job_id')->all(),
        );
        $this->assertSame(
            ['job-failed-new', 'job-failed-old'],
            $repository->failedForTask($task->task_id)->pluck('job_id')->all(),
        );
        $this->assertSame(
            2,
            $repository->countForTaskWithStatuses($task->task_id, [
                PipelineJob::STATUS_QUEUED,
                PipelineJob::STATUS_RUNNING,
            ]),
        );

        $this->assertSame(PipelineJob::STATUS_QUEUED, $queued->status);
        $this->assertSame(PipelineJob::STATUS_RUNNING, $running->status);
    }

    public function test_job_repository_reads_task_jobs_by_recent_update(): void
    {
        $task = $this->task();
        $oldest = $this->job($task, 'job-update-oldest', PipelineJob::STATUS_COMPLETED);
        $newest = $this->job($task, 'job-update-newest', PipelineJob::STATUS_FAILED);
        $middle = $this->job($task, 'job-update-middle', PipelineJob::STATUS_RUNNING);

        $oldest->forceFill(['updated_at' => Carbon::parse('2026-06-08 12:00:00')])->save();
        $newest->forceFill(['updated_at' => Carbon::parse('2026-06-08 12:10:00')])->save();
        $middle->forceFill(['updated_at' => Carbon::parse('2026-06-08 12:05:00')])->save();

        $jobs = app(PipelineJobRepository::class)->forTaskByRecentUpdate($task->task_id);

        $this->assertSame(
            ['job-update-newest', 'job-update-middle', 'job-update-oldest'],
            $jobs->pluck('job_id')->all(),
        );
    }

    public function test_job_repository_finds_and_upserts_task_jobs(): void
    {
        $task = $this->task('task-job-upsert');
        $repository = app(PipelineJobRepository::class);

        $job = $repository->upsertForTask('job-upsert', $task, [
            'job_type' => PipelineJob::TYPE_CONVERT,
            'source_url' => 'upload://source.pdf',
            'status' => PipelineJob::STATUS_QUEUED,
            'started_at' => Carbon::parse('2026-06-08 14:00:00'),
            'metadata' => ['source' => 'repository-test'],
        ]);

        $this->assertSame($task->task_id, $job->task_id);
        $this->assertSame(PipelineJob::STATUS_QUEUED, $job->status);
        $this->assertSame($job->id, $repository->findByJobId('job-upsert')?->id);
        $this->assertNull($repository->findByJobId('missing-job'));

        $updated = $repository->upsertForTask('job-upsert', $task, [
            'job_type' => PipelineJob::TYPE_CONVERT,
            'source_url' => 'upload://source.pdf',
            'status' => PipelineJob::STATUS_FAILED,
            'error_message' => 'Conversion failed',
            'metadata' => [
                'source' => 'repository-test',
                'retry_count' => 1,
            ],
        ]);

        $this->assertSame($job->id, $updated->id);
        $this->assertSame(PipelineJob::STATUS_FAILED, $updated->status);
        $this->assertSame('Conversion failed', $updated->error_message);
        $this->assertSame(1, $updated->metadata['retry_count']);

        $this->assertDatabaseHas('pipeline_jobs', [
            'job_id' => 'job-upsert',
            'task_id' => 'task-job-upsert',
            'status' => PipelineJob::STATUS_FAILED,
        ]);
    }

    public function test_job_repository_reads_failed_jobs_for_retry_and_marks_them_queued(): void
    {
        $task = $this->task('task-job-retry');
        $firstFailed = $this->job($task, 'job-retry-first', PipelineJob::STATUS_FAILED);
        $this->job($task, 'job-retry-completed', PipelineJob::STATUS_COMPLETED);
        $secondFailed = $this->job($task, 'job-retry-second', PipelineJob::STATUS_FAILED);

        $firstFailed->forceFill([
            'error_message' => 'First failed',
            'finished_at' => Carbon::parse('2026-06-08 14:30:00'),
            'metadata' => ['retry_count' => 2],
        ])->save();
        $secondFailed->forceFill([
            'error_message' => 'Second failed',
            'finished_at' => Carbon::parse('2026-06-08 14:35:00'),
        ])->save();

        $repository = app(PipelineJobRepository::class);
        $jobs = $repository->failedForRetry($task);

        $this->assertSame(
            ['job-retry-first', 'job-retry-second'],
            $jobs->pluck('job_id')->all(),
        );

        $updated = $repository->markQueuedForRetry($firstFailed, [
            'retry_count' => 3,
            'retried_at' => '2026-06-08T15:00:00+00:00',
        ]);

        $this->assertSame(PipelineJob::STATUS_QUEUED, $updated->status);
        $this->assertNull($updated->error_message);
        $this->assertNull($updated->finished_at);
        $this->assertSame(3, $updated->metadata['retry_count']);

        $this->assertDatabaseHas('pipeline_jobs', [
            'job_id' => 'job-retry-first',
            'status' => PipelineJob::STATUS_QUEUED,
            'error_message' => null,
            'finished_at' => null,
        ]);
    }

    public function test_job_repository_creates_scrape_jobs(): void
    {
        $task = $this->task('task-create-scrape-job');
        $startedAt = Carbon::parse('2026-06-08 15:30:00');
        $finishedAt = Carbon::parse('2026-06-08 15:31:00');
        $metadata = [
            'reason' => 'URL was already scraped by Laravel.',
            'dataset_id' => $task->dataset_id,
        ];

        $job = app(PipelineJobRepository::class)->createScrapeJob(
            'scrape-create-job',
            $task,
            'https://example.test/scrape',
            'hash-create-scrape-job',
            PipelineJob::STATUS_SKIPPED,
            $startedAt,
            $finishedAt,
            $metadata,
        );

        $this->assertSame('scrape-create-job', $job->job_id);
        $this->assertSame($task->task_id, $job->task_id);
        $this->assertSame(PipelineJob::TYPE_SCRAPE, $job->job_type);
        $this->assertSame('https://example.test/scrape', $job->source_url);
        $this->assertSame('hash-create-scrape-job', $job->content_hash);
        $this->assertSame(PipelineJob::STATUS_SKIPPED, $job->status);
        $this->assertTrue($startedAt->equalTo($job->started_at));
        $this->assertTrue($finishedAt->equalTo($job->finished_at));
        $this->assertSame($metadata, $job->metadata);

        $this->assertDatabaseHas('pipeline_jobs', [
            'job_id' => 'scrape-create-job',
            'task_id' => 'task-create-scrape-job',
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'status' => PipelineJob::STATUS_SKIPPED,
        ]);
    }

    public function test_scrape_history_repository_detects_completed_scrapes(): void
    {
        $repository = app(PipelineScrapeHistoryRepository::class);
        $scrapedElementUrl = 'https://history.example/scraped-element';
        $completedJobUrl = 'https://history.example/completed-job';
        $completedScrapeJobUrl = 'https://history.example/completed-scrape-job';
        $completedConvertJobUrl = 'https://history.example/completed-convert-job';
        $failedJobUrl = 'https://history.example/failed-job';

        $this->assertFalse($repository->hasCompletedScrape($scrapedElementUrl, hash('sha256', $scrapedElementUrl)));

        ScrapedElement::query()->create([
            'uuid' => (string) Str::uuid(),
            'title' => 'Already scraped',
            'page_url' => $scrapedElementUrl,
            'page_url_hash' => hash('sha256', $scrapedElementUrl),
            'content_hash' => hash('sha256', $scrapedElementUrl),
            'job_id' => 'existing-scrape-job',
        ]);

        $task = $this->task('task-scrape-history');
        $this->job($task, 'job-history-completed', PipelineJob::STATUS_COMPLETED, $completedJobUrl);
        $this->job($task, 'job-history-scrape-completed', PipelineJob::STATUS_COMPLETED, $completedScrapeJobUrl, PipelineJob::TYPE_SCRAPE);
        $this->job($task, 'job-history-convert-completed', PipelineJob::STATUS_COMPLETED, $completedConvertJobUrl);
        $this->job($task, 'job-history-failed', PipelineJob::STATUS_FAILED, $failedJobUrl);

        $this->assertTrue($repository->hasCompletedScrape($scrapedElementUrl, hash('sha256', $scrapedElementUrl)));
        $this->assertTrue($repository->hasCompletedScraperOutput($scrapedElementUrl, hash('sha256', $scrapedElementUrl)));
        $this->assertTrue($repository->hasScrapedElement($scrapedElementUrl, hash('sha256', $scrapedElementUrl)));
        $this->assertTrue($repository->hasCompletedOrSkippedJob($completedJobUrl));
        $this->assertTrue($repository->hasCompletedOrSkippedScrapeJob($completedScrapeJobUrl));
        $this->assertTrue($repository->hasCompletedScraperOutput($completedScrapeJobUrl, 'missing-hash'));
        $this->assertFalse($repository->hasCompletedOrSkippedScrapeJob($completedConvertJobUrl));
        $this->assertFalse($repository->hasCompletedScraperOutput($completedConvertJobUrl, 'missing-hash'));
        $this->assertFalse($repository->hasCompletedOrSkippedJob($failedJobUrl));
        $this->assertFalse($repository->hasCompletedScrape('https://history.example/missing', 'missing-hash'));
    }

    private function task(
        string $taskId = 'task_repository_read',
        ?Carbon $startedAt = null,
    ): PipelineTask
    {
        $dataset = $this->dataset('repository-read-dataset');

        return PipelineTask::query()->create([
            'task_id' => $taskId,
            'dataset_id' => $dataset->dataset_id,
            'status' => PipelineTask::STATUS_RUNNING,
            'started_at' => $startedAt ?? now(),
            'counters' => [],
            'metadata' => [],
        ]);
    }

    private function job(
        PipelineTask $task,
        string $jobId,
        string $status,
        ?string $sourceUrl = null,
        string $jobType = PipelineJob::TYPE_CONVERT,
    ): PipelineJob
    {
        return PipelineJob::query()->create([
            'job_id' => $jobId,
            'task_id' => $task->task_id,
            'job_type' => $jobType,
            'source_url' => $sourceUrl,
            'status' => $status,
            'started_at' => now(),
            'metadata' => [],
        ]);
    }

    private function dataset(string $datasetId): Dataset
    {
        $safeDatasetId = preg_replace('/[^a-zA-Z0-9_]+/', '_', $datasetId) ?: $datasetId;

        return Dataset::query()->firstOrCreate(
            ['dataset_id' => $datasetId],
            [
                'name' => 'Repository Read Dataset',
                'description' => null,
                'status' => Dataset::STATUS_ACTIVE,
                'qdrant_collection' => 'hawki_' . strtolower($safeDatasetId),
                'neo4j_namespace' => 'hawki_' . strtolower($safeDatasetId),
                'created_at' => now(),
            ],
        );
    }
}
