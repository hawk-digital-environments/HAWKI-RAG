<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Values\PipelineStoredUpload;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

#[Singleton]
readonly class PipelineJobRepository
{
    public function findByJobId(string $jobId): ?PipelineJob
    {
        return PipelineJob::query()
            ->where('job_id', $jobId)
            ->first();
    }

    /**
     * @param list<string> $jobIds
     * @return Collection<int, PipelineJob>
     */
    public function findByJobIds(array $jobIds): Collection
    {
        return PipelineJob::query()
            ->whereIn('job_id', $jobIds)
            ->get();
    }

    /**
     * @return Collection<int, PipelineJob>
     */
    public function forTask(string $taskId): Collection
    {
        return PipelineJob::query()
            ->where('task_id', $taskId)
            ->get();
    }

    /**
     * @return Collection<int, PipelineJob>
     */
    public function forTaskOrdered(string $taskId): Collection
    {
        return PipelineJob::query()
            ->where('task_id', $taskId)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, PipelineJob>
     */
    public function failedForTask(string $taskId): Collection
    {
        return PipelineJob::query()
            ->where('task_id', $taskId)
            ->where('status', PipelineJob::STATUS_FAILED)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Collection<int, PipelineJob>
     */
    public function failedForRetry(PipelineTask $task): Collection
    {
        return PipelineJob::query()
            ->where('task_id', $task->task_id)
            ->where('status', PipelineJob::STATUS_FAILED)
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, PipelineJob>
     */
    public function failedForRecoveryList(?string $taskId, ?string $datasetId, int $limit): Collection
    {
        $limit = max(1, min(500, $limit));

        return $this->failedForRecoveryQuery($taskId, $datasetId)
            ->with('task')
            ->orderByDesc('finished_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, PipelineJob>
     */
    public function failedForRecovery(?string $taskId = null, ?string $datasetId = null): Collection
    {
        return $this->failedForRecoveryQuery($taskId, $datasetId)->get();
    }

    /**
     * @return Collection<int, PipelineJob>
     */
    public function forTaskByRecentUpdate(string $taskId): Collection
    {
        return PipelineJob::query()
            ->where('task_id', $taskId)
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * @param list<string> $statuses
     */
    public function countForTaskWithStatuses(string $taskId, array $statuses): int
    {
        return PipelineJob::query()
            ->where('task_id', $taskId)
            ->whereIn('status', $statuses)
            ->count();
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

    private function failedForRecoveryQuery(?string $taskId, ?string $datasetId)
    {
        return PipelineJob::query()
            ->where('status', PipelineJob::STATUS_FAILED)
            ->when($taskId, fn ($query) => $query->where('task_id', $taskId))
            ->when($datasetId, fn ($query) => $query->whereHas('task', fn ($taskQuery) => $taskQuery->where('dataset_id', $datasetId)));
    }
}
