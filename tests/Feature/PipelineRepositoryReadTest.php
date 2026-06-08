<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Repositories\PipelineJobRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PipelineRepositoryReadTest extends TestCase
{
    use RefreshDatabase;

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

    private function task(
        string $taskId = 'task_repository_read',
        ?Carbon $startedAt = null,
    ): PipelineTask
    {
        $dataset = Dataset::query()->firstOrCreate(
            ['dataset_id' => 'repository-read-dataset'],
            [
                'name' => 'Repository Read Dataset',
                'description' => null,
                'status' => Dataset::STATUS_ACTIVE,
                'qdrant_collection' => 'hawki_repository_read_dataset',
                'neo4j_namespace' => 'hawki_repository_read_dataset',
                'created_at' => now(),
            ],
        );

        return PipelineTask::query()->create([
            'task_id' => $taskId,
            'dataset_id' => $dataset->dataset_id,
            'status' => PipelineTask::STATUS_RUNNING,
            'started_at' => $startedAt ?? now(),
            'counters' => [],
            'metadata' => [],
        ]);
    }

    private function job(PipelineTask $task, string $jobId, string $status): PipelineJob
    {
        return PipelineJob::query()->create([
            'job_id' => $jobId,
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_CONVERT,
            'status' => $status,
            'started_at' => now(),
            'metadata' => [],
        ]);
    }
}
