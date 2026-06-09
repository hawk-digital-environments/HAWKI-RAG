<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories\Queries;

use App\Models\PipelineJob;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
readonly class ActivePipelineJobsQuery
{
    public function findByJobId(string $jobId): ?PipelineJob
    {
        return PipelineJob::query()
            ->where('job_id', $jobId)
            ->first();
    }

    public function findWithOrderedStagesByJobId(string $jobId): ?PipelineJob
    {
        return PipelineJob::query()
            ->with(['stages' => fn ($query) => $query->orderBy('id')])
            ->where('job_id', $jobId)
            ->first();
    }

    public function findWithTaskByJobId(string $jobId): ?PipelineJob
    {
        return PipelineJob::query()
            ->with('task')
            ->where('job_id', $jobId)
            ->first();
    }

    public function firstForTaskAndType(string $taskId, string $jobType): ?PipelineJob
    {
        return PipelineJob::query()
            ->where('task_id', $taskId)
            ->where('job_type', $jobType)
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

    public function hasCompletedOrSkippedConversion(string $path, string $contentHash): bool
    {
        return PipelineJob::query()
            ->where('job_type', PipelineJob::TYPE_CONVERT)
            ->where(function ($query) use ($path, $contentHash): void {
                $query->where('local_path', $path)
                    ->orWhere('content_hash', $contentHash);
            })
            ->whereIn('status', [PipelineJob::STATUS_COMPLETED, PipelineJob::STATUS_SKIPPED])
            ->exists();
    }
}
