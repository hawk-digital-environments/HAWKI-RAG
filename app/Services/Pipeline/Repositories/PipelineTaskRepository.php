<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\Dataset;
use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Models\PipelineTask;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

#[Singleton]
readonly class PipelineTaskRepository
{
    public function __construct(private DatabaseManager $database)
    {
    }

    public function findByTaskId(string $taskId): ?PipelineTask
    {
        return PipelineTask::query()
            ->where('task_id', $taskId)
            ->first();
    }

    public function findByTaskIdOrFail(string $taskId): PipelineTask
    {
        return PipelineTask::query()
            ->where('task_id', $taskId)
            ->firstOrFail();
    }

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
            ->with(['jobs' => fn ($query) => $query->with(['stages' => fn ($stageQuery) => $stageQuery->orderBy('id')])->orderBy('id')])
            ->where('task_id', $taskId)
            ->first();
    }

    public function sourceHasJobsOutsideTask(string $sourceId, string $taskId): bool
    {
        return PipelineJob::query()
            ->where('source_id', $sourceId)
            ->where(function ($query) use ($taskId): void {
                $query
                    ->where('task_id', '!=', $taskId)
                    ->orWhereNull('task_id');
            })
            ->exists();
    }

    /**
     * @param array<string, int> $counters
     * @param array<string, mixed> $metadata
     */
    public function createRunningTask(
        string $taskId,
        Dataset $dataset,
        Carbon $startedAt,
        array $counters,
        array $metadata,
    ): PipelineTask
    {
        return PipelineTask::query()->create([
            'task_id' => $taskId,
            'dataset_id' => $dataset->dataset_id,
            'status' => PipelineTask::STATUS_RUNNING,
            'started_at' => $startedAt,
            'counters' => $counters,
            'metadata' => $metadata,
        ]);
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
        return $this->createRunningTask($taskId, $dataset, $startedAt, [], $metadata);
    }

    public function markFailed(PipelineTask $task, Carbon $failedAt): PipelineTask
    {
        $task->forceFill([
            'status' => PipelineTask::STATUS_FAILED,
            'finished_at' => $failedAt,
        ])->save();

        return $task->refresh();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function markFailedJobsRetried(PipelineTask $task, array $metadata): PipelineTask
    {
        return $this->markRecoveryRunning($task, $metadata);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function markRecoveryRunning(PipelineTask $task, array $metadata): PipelineTask
    {
        $task->forceFill([
            'status' => PipelineTask::STATUS_RUNNING,
            'finished_at' => null,
            'metadata' => $metadata,
        ])->save();

        return $task->refresh();
    }

    /**
     * @param array<string, int> $counters
     */
    public function updateStatusCounters(
        PipelineTask $task,
        string $status,
        ?Carbon $finishedAt,
        array $counters,
    ): PipelineTask {
        $task->forceFill([
            'status' => $status,
            'finished_at' => $finishedAt,
            'counters' => $counters,
        ])->save();

        return $task->refresh();
    }

    public function deleteHistory(string $taskId): bool
    {
        return $this->database->transaction(function () use ($taskId): bool {
            $task = $this->findByTaskId($taskId);

            if (! $task) {
                return false;
            }

            $jobIds = PipelineJob::query()
                ->where('task_id', $taskId)
                ->pluck('id');

            if ($jobIds->isNotEmpty()) {
                PipelineStageState::query()
                    ->whereIn('pipeline_job_id', $jobIds)
                    ->delete();

                PipelineJob::query()
                    ->whereIn('id', $jobIds)
                    ->delete();
            }

            $task->delete();

            return true;
        });
    }
}
