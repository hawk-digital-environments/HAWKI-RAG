<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\PipelineTask;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Repositories\Queries\PipelineTaskJobsQuery;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineTaskStatusRefresher
{
    public function __construct(
        private PipelineTaskCounterService $counters,
        private PipelineTaskStatusService $statuses,
        private PipelineTaskRepository $taskRepository,
        private PipelineTaskJobsQuery $taskJobs,
    ) {
    }

    public function recalculate(PipelineTask|string $task): PipelineTask
    {
        $task = $task instanceof PipelineTask
            ? $task
            : $this->taskRepository->findByTaskIdOrFail($task);

        $jobs = $this->taskJobs->forTask($task->task_id);
        $counters = $this->counters->forJobs($jobs);
        $status = $this->statuses->resolve($task, $counters, $jobs->isNotEmpty());

        return $this->taskRepository
            ->updateStatusCounters($task, $status['status'], $status['finished_at'], $counters)
            ->setRelation('jobs', $jobs);
    }

    public function activeJobCount(PipelineTask $task): int
    {
        return $this->taskJobs->countForTaskWithStatuses($task->task_id, $this->statuses->activeJobStatuses());
    }
}
