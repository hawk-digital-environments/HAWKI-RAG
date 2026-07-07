<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories\Queries;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

#[Singleton]
readonly class FailedPipelineJobsQuery
{
    /**
     * @return Collection<int, PipelineJob>
     */
    public function forTask(string $taskId): Collection
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
    public function forRetry(PipelineTask $task): Collection
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
    public function forRecoveryList(?string $taskId, ?string $heapId, int $limit): Collection
    {
        $limit = max(1, min(500, $limit));

        return $this->forRecoveryQuery($taskId, $heapId)
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
    public function forRecovery(?string $taskId = null, ?string $heapId = null): Collection
    {
        return $this->forRecoveryQuery($taskId, $heapId)->get();
    }

    private function forRecoveryQuery(?string $taskId, ?string $heapId): Builder
    {
        return PipelineJob::query()
            ->where('status', PipelineJob::STATUS_FAILED)
            ->when($taskId, fn ($query) => $query->where('task_id', $taskId))
            ->when($heapId, fn ($query) => $query->whereHas('task', fn ($taskQuery) => $taskQuery->where('dataset_id', $heapId)));
    }
}
