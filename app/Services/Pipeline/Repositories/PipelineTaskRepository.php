<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\Dataset;
use App\Models\PipelineTask;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

#[Singleton]
readonly class PipelineTaskRepository
{
    /**
     * @return Collection<int, PipelineTask>
     */
    public function recent(int $limit = 30): Collection
    {
        $limit = max(1, min(250, $limit));

        return PipelineTask::query()
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function findWithOrderedJobs(string $taskId): ?PipelineTask
    {
        return PipelineTask::query()
            ->with(['jobs' => fn ($query) => $query->orderBy('id')])
            ->where('task_id', $taskId)
            ->first();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function createUploadTask(
        string $taskId,
        Dataset $dataset,
        Carbon $startedAt,
        array $metadata,
    ): PipelineTask
    {
        return PipelineTask::query()->create([
            'task_id' => $taskId,
            'dataset_id' => $dataset->dataset_id,
            'status' => PipelineTask::STATUS_RUNNING,
            'started_at' => $startedAt,
            'counters' => [],
            'metadata' => $metadata,
        ]);
    }

    public function markFailed(PipelineTask $task, Carbon $failedAt): PipelineTask
    {
        $task->forceFill([
            'status' => PipelineTask::STATUS_FAILED,
            'finished_at' => $failedAt,
        ])->save();

        return $task->refresh();
    }
}
