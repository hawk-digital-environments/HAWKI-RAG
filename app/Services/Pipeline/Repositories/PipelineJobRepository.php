<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Repositories\Queries\ActivePipelineJobsQuery;
use App\Services\Pipeline\Repositories\Queries\FailedPipelineJobsQuery;
use App\Services\Pipeline\Repositories\Queries\PipelineTaskJobsQuery;
use App\Services\Pipeline\Values\PipelineStoredUpload;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

#[Singleton]
readonly class PipelineJobRepository
{
    public function __construct(
        private ActivePipelineJobsQuery $activeJobs,
        private FailedPipelineJobsQuery $failedJobs,
        private PipelineTaskJobsQuery $taskJobs,
    ) {
    }

    public function findByJobId(string $jobId): ?PipelineJob
    {
        return $this->activeJobs->findByJobId($jobId);
    }

    public function findWithOrderedStagesByJobId(string $jobId): ?PipelineJob
    {
        return $this->activeJobs->findWithOrderedStagesByJobId($jobId);
    }

    public function findWithTaskByJobId(string $jobId): ?PipelineJob
    {
        return $this->activeJobs->findWithTaskByJobId($jobId);
    }

    public function firstForTaskAndType(string $taskId, string $jobType): ?PipelineJob
    {
        return $this->activeJobs->firstForTaskAndType($taskId, $jobType);
    }

    /**
     * @param list<string> $jobIds
     * @return Collection<int, PipelineJob>
     */
    public function findByJobIds(array $jobIds): Collection
    {
        return $this->activeJobs->findByJobIds($jobIds);
    }

    /**
     * @return Collection<int, PipelineJob>
     */
    public function forTask(string $taskId): Collection
    {
        return $this->taskJobs->forTask($taskId);
    }

    /**
     * @return Collection<int, PipelineJob>
     */
    public function forTaskOrdered(string $taskId): Collection
    {
        return $this->taskJobs->forTaskOrdered($taskId);
    }

    /**
     * @return Collection<int, PipelineJob>
     */
    public function failedForTask(string $taskId): Collection
    {
        return $this->failedJobs->forTask($taskId);
    }

    /**
     * @return Collection<int, PipelineJob>
     */
    public function failedForRetry(PipelineTask $task): Collection
    {
        return $this->failedJobs->forRetry($task);
    }

    /**
     * @return Collection<int, PipelineJob>
     */
    public function failedForRecoveryList(?string $taskId, ?string $datasetId, int $limit): Collection
    {
        return $this->failedJobs->forRecoveryList($taskId, $datasetId, $limit);
    }

    /**
     * @return Collection<int, PipelineJob>
     */
    public function failedForRecovery(?string $taskId = null, ?string $datasetId = null): Collection
    {
        return $this->failedJobs->forRecovery($taskId, $datasetId);
    }

    /**
     * @return Collection<int, PipelineJob>
     */
    public function forTaskByRecentUpdate(string $taskId): Collection
    {
        return $this->taskJobs->forTaskByRecentUpdate($taskId);
    }

    /**
     * @param list<string> $statuses
     */
    public function countForTaskWithStatuses(string $taskId, array $statuses): int
    {
        return $this->taskJobs->countForTaskWithStatuses($taskId, $statuses);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function createUploadConvertJob(
        string $jobId,
        PipelineTask $task,
        string $sourceUrl,
        PipelineStoredUpload $storedUpload,
        Carbon $startedAt,
        array $metadata,
    ): PipelineJob
    {
        return PipelineJob::query()->create([
            'job_id' => $jobId,
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_CONVERT,
            'source_url' => $sourceUrl,
            'local_path' => $storedUpload->localPath,
            'content_hash' => $storedUpload->contentHash,
            'status' => PipelineJob::STATUS_QUEUED,
            'started_at' => $startedAt,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function createScrapeJob(
        string $jobId,
        PipelineTask $task,
        string $sourceUrl,
        string $contentHash,
        string $status,
        Carbon $startedAt,
        ?Carbon $finishedAt,
        array $metadata,
    ): PipelineJob
    {
        return PipelineJob::query()->create([
            'job_id' => $jobId,
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => $sourceUrl,
            'content_hash' => $contentHash,
            'status' => $status,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'metadata' => $metadata,
        ]);
    }

    public function hasCompletedOrSkippedConversion(string $path, string $contentHash): bool
    {
        return $this->activeJobs->hasCompletedOrSkippedConversion($path, $contentHash);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function ensureStateJob(
        string $jobId,
        array $attributes,
        Carbon $startedAt,
        string $defaultStatus,
    ): PipelineJob {
        $job = PipelineJob::query()->firstOrNew(['job_id' => $jobId]);
        $job->fill($attributes);

        if (!$job->started_at) {
            $job->started_at = $startedAt;
        }
        if (!$job->status) {
            $job->status = $defaultStatus;
        }

        $job->save();

        return $job->refresh();
    }

    public function firstOrCreateClaimJob(string $jobId, string $stage, Carbon $startedAt): PipelineJob
    {
        return PipelineJob::query()->firstOrCreate(
            ['job_id' => $jobId],
            [
                'status' => PipelineJob::STATUS_PENDING,
                'current_stage' => $stage,
                'started_at' => $startedAt,
            ],
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function upsertForTask(string $jobId, PipelineTask $task, array $attributes): PipelineJob
    {
        return PipelineJob::query()->updateOrCreate(
            ['job_id' => $jobId],
            array_merge($attributes, [
                'task_id' => $task->task_id,
            ]),
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function upsertEventState(string $jobId, array $attributes): PipelineJob
    {
        return PipelineJob::query()->updateOrCreate(
            ['job_id' => $jobId],
            $attributes,
        );
    }

    public function markFailed(PipelineJob $job, string $message, Carbon $failedAt): PipelineJob
    {
        $job->forceFill([
            'status' => PipelineJob::STATUS_FAILED,
            'error_message' => $message,
            'finished_at' => $failedAt,
        ])->save();

        return $job->refresh();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function markQueuedForRetry(PipelineJob $job, array $metadata): PipelineJob
    {
        $job->forceFill([
            'status' => PipelineJob::STATUS_QUEUED,
            'error_message' => null,
            'finished_at' => null,
            'metadata' => $metadata,
        ])->save();

        return $job->refresh();
    }

    public function lockForRecovery(PipelineJob $job): ?PipelineJob
    {
        return PipelineJob::query()
            ->whereKey($job->getKey())
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function markRecoveryQueued(PipelineJob $job, array $metadata): PipelineJob
    {
        $job->forceFill([
            'status' => PipelineJob::STATUS_QUEUED,
            'error_message' => null,
            'finished_at' => null,
            'completed_at' => null,
            'metadata' => $metadata,
        ])->save();

        return $job->refresh();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function markRecoveryPublishFailed(
        PipelineJob $job,
        string $message,
        Carbon $failedAt,
        array $metadata,
    ): PipelineJob {
        $job->forceFill([
            'status' => PipelineJob::STATUS_FAILED,
            'error_message' => $message,
            'finished_at' => $failedAt,
            'metadata' => $metadata,
        ])->save();

        return $job->refresh();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function markScrapeMonitorCompleted(
        PipelineJob $job,
        string $datasetPath,
        Carbon $completedAt,
        array $metadata,
    ): PipelineJob {
        $job->forceFill([
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'status' => PipelineJob::STATUS_COMPLETED,
            'local_path' => $datasetPath,
            'completed_at' => $completedAt,
            'finished_at' => $completedAt,
            'metadata' => $metadata,
        ])->save();

        return $job->refresh()->loadMissing('task');
    }

    /**
     * @param array{total:int,processed:int,failed:int,skipped:int} $counts
     * @param array<string, mixed> $attributes
     */
    public function updateStageRollup(
        PipelineJob $job,
        string $currentStage,
        string $status,
        array $counts,
        ?Carbon $completedAt,
        array $attributes,
    ): PipelineJob {
        $job->current_stage = $attributes['current_stage'] ?? $currentStage;
        $job->status = $status;

        if (isset($attributes['dataset_path'])) {
            $job->dataset_path = $attributes['dataset_path'];
        }
        if (isset($attributes['source_url'])) {
            $job->source_url = $attributes['source_url'];
        }
        if (isset($attributes['label'])) {
            $job->label = $attributes['label'];
        }

        $job->total_documents = $counts['total'];
        $job->processed_documents = $counts['processed'];
        $job->failed_documents = $counts['failed'];
        $job->skipped_documents = $counts['skipped'];
        $job->completed_at = $completedAt;
        $job->save();

        return $job->refresh();
    }
}
