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
        private PipelineJobCreationRepository $creation,
        private PipelineJobRecoveryRepository $recovery,
        private PipelineJobStateMutationRepository $states,
        private PipelineJobRollupRepository $rollups,
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
        return $this->creation->createUploadConvertJob($jobId, $task, $sourceUrl, $storedUpload, $startedAt, $metadata);
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
        return $this->creation->createScrapeJob($jobId, $task, $sourceUrl, $contentHash, $status, $startedAt, $finishedAt, $metadata);
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
        return $this->creation->ensureStateJob($jobId, $attributes, $startedAt, $defaultStatus);
    }

    public function firstOrCreateClaimJob(string $jobId, string $stage, Carbon $startedAt): PipelineJob
    {
        return $this->creation->firstOrCreateClaimJob($jobId, $stage, $startedAt);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function upsertForTask(string $jobId, PipelineTask $task, array $attributes): PipelineJob
    {
        return $this->creation->upsertForTask($jobId, $task, $attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function upsertEventState(string $jobId, array $attributes): PipelineJob
    {
        return $this->creation->upsertEventState($jobId, $attributes);
    }

    public function markFailed(PipelineJob $job, string $message, Carbon $failedAt): PipelineJob
    {
        return $this->states->markFailed($job, $message, $failedAt);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function markQueuedForRetry(PipelineJob $job, array $metadata): PipelineJob
    {
        return $this->recovery->markQueuedForRetry($job, $metadata);
    }

    public function lockForRecovery(PipelineJob $job): ?PipelineJob
    {
        return $this->recovery->lockForRecovery($job);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function markRecoveryQueued(PipelineJob $job, array $metadata): PipelineJob
    {
        return $this->recovery->markRecoveryQueued($job, $metadata);
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
        return $this->recovery->markRecoveryPublishFailed($job, $message, $failedAt, $metadata);
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
        return $this->states->markScrapeMonitorCompleted($job, $datasetPath, $completedAt, $metadata);
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
        return $this->rollups->updateStageRollup($job, $currentStage, $status, $counts, $completedAt, $attributes);
    }
}
