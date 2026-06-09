<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories\Queries;

use App\Models\PipelineJob;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
readonly class PipelineTaskJobsQuery
{
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
}
