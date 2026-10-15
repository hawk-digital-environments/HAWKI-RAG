<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\Dataset;
use App\Models\IngestionSource;
use App\Models\ManagedDocument;
use App\Models\ManagedDocumentOutput;
use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Models\PipelineTask;
use App\Services\Document\Values\ManagedDocumentId;
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
            ->where(function ($query): void {
                $query->whereNull('metadata->history_hidden')->orWhere('metadata->history_hidden', false);
            })
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

    /** Keep managed ownership evidence; removing history only hides the task list entry. */
    public function hideManagedHistory(PipelineTask $task): bool
    {
        $request = $task->metadata['request'] ?? [];
        $managed = ManagedDocumentId::fromRequestMetadata($request['metadata'] ?? $request) !== null
            || ManagedDocument::query()->where('latest_task_id', $task->task_id)->exists()
            || ManagedDocumentOutput::query()->where('task_id', $task->task_id)->exists()
            || IngestionSource::query()->where(function ($query) use ($task): void {
                $query->where('task_id', $task->task_id)->orWhereIn('source_id', $task->jobs->pluck('source_id')->filter());
            })->get()->contains(function (IngestionSource $source): bool {
                $request = $source->metadata['request'] ?? [];

                return isset($source->metadata['managed_dispatch'])
                    || ManagedDocumentId::fromRequestMetadata($request['metadata'] ?? $request) !== null;
            });
        if (! $managed) {
            return false;
        }
        $this->database->transaction(function () use ($task): void {
            $current = PipelineTask::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
            $current->forceFill(['metadata' => [...($current->metadata ?? []), 'history_hidden' => true]])->save();
        });

        return true;
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
        return $this->database->transaction(function () use ($task, $metadata): PipelineTask {
            $current = PipelineTask::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();
            if (($current->metadata['history_hidden'] ?? false) === true) {
                $metadata['history_hidden'] = true;
            }
            $current->forceFill([
                'status' => PipelineTask::STATUS_RUNNING,
                'finished_at' => null,
                'metadata' => $metadata,
            ])->save();

            return $task->refresh();
        });
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
