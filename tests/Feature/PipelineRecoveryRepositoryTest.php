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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PipelineRecoveryRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_repository_reads_recovery_failed_jobs_with_filters_and_limits(): void
    {
        $this->dataset('dataset-recovery-a');
        $this->dataset('dataset-recovery-b');
        $taskA = $this->task('task-recovery-a', 'dataset-recovery-a');
        $taskB = $this->task('task-recovery-b', 'dataset-recovery-b');
        $older = $this->job($taskA, 'job-recovery-older', PipelineJob::STATUS_FAILED);
        $newer = $this->job($taskA, 'job-recovery-newer', PipelineJob::STATUS_FAILED);
        $otherDataset = $this->job($taskB, 'job-recovery-other-dataset', PipelineJob::STATUS_FAILED);
        $this->job($taskA, 'job-recovery-queued', PipelineJob::STATUS_QUEUED);

        $older->forceFill([
            'finished_at' => Carbon::parse('2026-06-08 11:00:00'),
            'updated_at' => Carbon::parse('2026-06-08 11:00:00'),
        ])->save();
        $newer->forceFill([
            'finished_at' => Carbon::parse('2026-06-08 12:00:00'),
            'updated_at' => Carbon::parse('2026-06-08 12:00:00'),
        ])->save();
        $otherDataset->forceFill([
            'finished_at' => Carbon::parse('2026-06-08 13:00:00'),
            'updated_at' => Carbon::parse('2026-06-08 13:00:00'),
        ])->save();

        $repository = app(PipelineJobRepository::class);

        $this->assertSame(
            ['job-recovery-other-dataset', 'job-recovery-newer'],
            $repository->failedForRecoveryList(null, null, 2)->pluck('job_id')->all(),
        );
        $this->assertSame(
            ['job-recovery-newer', 'job-recovery-older'],
            $repository->failedForRecoveryList('task-recovery-a', null, 10)->pluck('job_id')->all(),
        );
        $this->assertSame(
            ['job-recovery-other-dataset'],
            $repository->failedForRecovery(null, 'dataset-recovery-b')->pluck('job_id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['job-recovery-newer', 'job-recovery-other-dataset'],
            $repository->findByJobIds(['job-recovery-newer', 'missing-job', 'job-recovery-other-dataset'])
                ->pluck('job_id')
                ->all(),
        );
    }

    public function test_recovery_repositories_lock_and_prepare_job_and_task_state(): void
    {
        $this->dataset('dataset-recovery-state');
        $task = $this->task('task-recovery-state', 'dataset-recovery-state');
        $job = $this->job($task, 'job-recovery-state', PipelineJob::STATUS_FAILED);
        $job->forceFill([
            'error_message' => 'Worker failed',
            'completed_at' => Carbon::parse('2026-06-08 13:00:00'),
            'finished_at' => Carbon::parse('2026-06-08 13:01:00'),
            'metadata' => ['retry_count' => 1],
        ])->save();

        $jobRepository = app(PipelineJobRepository::class);
        $taskRepository = app(PipelineTaskRepository::class);

        $locked = DB::transaction(fn (): ?PipelineJob => $jobRepository->lockForRecovery($job));

        $this->assertNotNull($locked);
        $this->assertSame($job->id, $locked->id);

        $queued = $jobRepository->markRecoveryQueued($job, [
            'retry_count' => 2,
            'retried_at' => '2026-06-08T13:05:00+00:00',
        ]);

        $this->assertSame(PipelineJob::STATUS_QUEUED, $queued->status);
        $this->assertNull($queued->error_message);
        $this->assertNull($queued->finished_at);
        $this->assertNull($queued->completed_at);
        $this->assertSame(2, $queued->metadata['retry_count']);

        $running = $taskRepository->markRecoveryRunning($task, [
            'last_recovery_event' => ['event' => 'job.recovery_requested'],
        ]);

        $this->assertSame(PipelineTask::STATUS_RUNNING, $running->status);
        $this->assertNull($running->finished_at);
        $this->assertSame('job.recovery_requested', $running->metadata['last_recovery_event']['event']);
    }

    public function test_job_repository_marks_recovery_publish_failures(): void
    {
        $this->dataset('dataset-recovery-publish-failed');
        $task = $this->task('task-recovery-publish-failed', 'dataset-recovery-publish-failed');
        $job = $this->job($task, 'job-recovery-publish-failed', PipelineJob::STATUS_QUEUED);
        $failedAt = Carbon::parse('2026-06-08 14:00:00');

        $failed = app(PipelineJobRepository::class)->markRecoveryPublishFailed(
            $job,
            'RabbitMQ unavailable',
            $failedAt,
            [
                'last_recovery_event' => [
                    'event' => 'recovery_publish_failed',
                    'error_message' => 'RabbitMQ unavailable',
                ],
            ],
        );

        $this->assertSame(PipelineJob::STATUS_FAILED, $failed->status);
        $this->assertSame('RabbitMQ unavailable', $failed->error_message);
        $this->assertTrue($failedAt->equalTo($failed->finished_at));
        $this->assertSame('recovery_publish_failed', $failed->metadata['last_recovery_event']['event']);

        $this->assertDatabaseHas('pipeline_jobs', [
            'job_id' => 'job-recovery-publish-failed',
            'status' => PipelineJob::STATUS_FAILED,
            'error_message' => 'RabbitMQ unavailable',
        ]);
    }

    private function dataset(string $datasetId): Dataset
    {
        $safeDatasetId = str_replace('-', '_', $datasetId);

        return Dataset::query()->create([
            'dataset_id' => $datasetId,
            'name' => $datasetId,
            'description' => null,
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_' . $safeDatasetId,
            'neo4j_namespace' => 'hawki_' . $safeDatasetId,
            'created_at' => now(),
        ]);
    }

    private function task(string $taskId, string $datasetId): PipelineTask
    {
        return PipelineTask::query()->create([
            'task_id' => $taskId,
            'dataset_id' => $datasetId,
            'status' => PipelineTask::STATUS_FAILED,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinute(),
            'counters' => ['failed' => 1],
            'metadata' => [],
        ]);
    }

    private function job(PipelineTask $task, string $jobId, string $status): PipelineJob
    {
        return PipelineJob::query()->create([
            'job_id' => $jobId,
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_CONVERT,
            'source_url' => 'https://example.test/' . $jobId,
            'local_path' => '/app/shared/' . $jobId . '.md',
            'content_hash' => 'hash-' . $jobId,
            'status' => $status,
            'error_message' => $status === PipelineJob::STATUS_FAILED ? 'Worker failed' : null,
            'started_at' => now()->subMinutes(5),
            'finished_at' => $status === PipelineJob::STATUS_FAILED ? now()->subMinute() : null,
            'metadata' => [],
        ]);
    }
}
