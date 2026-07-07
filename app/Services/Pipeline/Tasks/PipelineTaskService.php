<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Repositories\Queries\FailedPipelineJobsQuery;
use App\Services\Pipeline\Repositories\Queries\PipelineTaskJobsQuery;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
class PipelineTaskService
{
    public function __construct(
        private readonly PipelineTaskCounterService $counters,
        private readonly PipelineTaskPayloadService $payloads,
        private readonly PipelineTaskJobUpdater $updater,
        private readonly PipelineTaskRetryService $retries,
        private readonly PipelineTaskCancellationService $cancellations,
        private readonly PipelineTaskTimelineService $timeline,
        private readonly PipelineTaskStatusRefresher $refresher,
        private readonly PipelineTaskStorageCleanupService $storageCleanup,
        private readonly PipelineTaskRepository $taskRepository,
        private readonly PipelineTaskJobsQuery $taskJobs,
        private readonly FailedPipelineJobsQuery $failedJobs,
    ) {}

    public function show(string $taskId): ?array
    {
        $task = $this->taskRepository->findWithOrderedJobs($taskId);
        if (! $task) {
            return null;
        }

        $task = $this->recalculateTaskStatus($task);

        return $this->payloads->detail($task, $this->activeJobCount($task), $this->counters->defaults());
    }

    public function list(int $limit = 30): array
    {
        return $this->taskRepository->recent($limit)
            ->map(function (PipelineTask $task): array {
                $task = $this->recalculateTaskStatus($task);

                return $this->payloads->summary($task, $this->activeJobCount($task), $this->counters->defaults());
            })
            ->all();
    }

    public function jobs(string $taskId): array
    {
        return $this->taskJobs->forTaskOrdered($taskId)
            ->map(fn (PipelineJob $job) => $this->payloads->job($job))
            ->all();
    }

    public function failedJobs(string $taskId): array
    {
        return $this->failedJobs->forTask($taskId)
            ->map(fn (PipelineJob $job) => $this->payloads->job($job))
            ->all();
    }

    public function recentEvents(string $taskId, int $limit = 50, array $filters = []): array
    {
        return $this->timeline->recentEvents($taskId, $limit, $filters);
    }

    public function eventFilters(string $taskId): array
    {
        return $this->timeline->eventFilters($taskId);
    }

    public function upsertJob(string $taskId, array $input): PipelineJob
    {
        return $this->updater->upsertJob($taskId, $input);
    }

    public function retryFailedJobs(string $taskId): ?PipelineTask
    {
        return $this->retries->retryFailedJobs($taskId);
    }

    public function retry(string $taskId): ?PipelineTask
    {
        return $this->retryFailedJobs($taskId);
    }

    public function cancel(string $taskId): ?PipelineTask
    {
        return $this->cancellations->cancel($taskId);
    }

    /**
     * @return array{deleted: bool, storageCleanup: array<string, mixed>}|null
     */
    public function delete(string $taskId): ?array
    {
        $task = $this->taskRepository->findWithOrderedJobs($taskId);
        if (! $task) {
            return null;
        }

        $storageCleanup = $this->storageCleanup->deleteForTask($task);
        if (! ($storageCleanup['ok'] ?? false)) {
            return [
                'deleted' => false,
                'storageCleanup' => $storageCleanup,
            ];
        }

        return [
            'deleted' => $this->taskRepository->deleteHistory($taskId),
            'storageCleanup' => $storageCleanup,
        ];
    }

    public function completeIfIdle(string $taskId): ?PipelineTask
    {
        $task = $this->taskRepository->findByTaskId($taskId);

        return $task ? $this->recalculateTaskStatus($task) : null;
    }

    public function recalculateTaskStatus(PipelineTask|string $task): PipelineTask
    {
        return $this->refresher->recalculate($task);
    }

    public function refreshCounters(PipelineTask $task): PipelineTask
    {
        return $this->recalculateTaskStatus($task);
    }

    private function activeJobCount(PipelineTask $task): int
    {
        return $this->refresher->activeJobCount($task);
    }
}
