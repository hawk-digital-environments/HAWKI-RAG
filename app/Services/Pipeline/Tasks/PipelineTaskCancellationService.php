<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use App\Services\Pipeline\Repositories\PipelineJobStateMutationRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Repositories\Queries\PipelineTaskJobsQuery;
use App\Services\Temporal\TemporalOrchestrationClient;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineTaskCancellationService
{
    public function __construct(
        private PipelineTaskRepository $taskRepository,
        private PipelineTaskJobsQuery $taskJobs,
        private PipelineJobStateMutationRepository $jobStates,
        private IngestionSourceRepository $sources,
        private PipelineTaskStatusRefresher $refresher,
        private TemporalOrchestrationClient $temporal,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    public function cancel(string $taskId): ?PipelineTask
    {
        $task = $this->taskRepository->findByTaskId($taskId);
        if (! $task) {
            return null;
        }

        $jobs = $this->taskJobs->forTask($taskId)
            ->filter(fn (PipelineJob $job): bool => $this->isCancellableTemporalJob($job));

        foreach ($jobs as $job) {
            $workflowId = (string) $job->temporal_workflow_id;
            $runId = is_string($job->temporal_run_id) && trim($job->temporal_run_id) !== ''
                ? $job->temporal_run_id
                : null;

            $this->temporal->cancelWorkflow($workflowId, $runId);

            $metadata = $job->metadata ?? [];
            $metadata['cancelled_at'] = $this->timestamp();
            $this->jobStates->markTemporalCancellationRequested($job, $this->now(), $metadata);

            if (is_string($job->source_id) && $job->source_id !== '') {
                $source = $this->sources->findBySourceId($job->source_id);
                if ($source) {
                    $this->sources->markCancelled($source);
                }
            }
        }

        return $this->refresher->recalculate($task);
    }

    private function isCancellableTemporalJob(PipelineJob $job): bool
    {
        return in_array($job->status, PipelineJob::ACTIVE_STATUSES, true)
            && is_string($job->temporal_workflow_id)
            && trim($job->temporal_workflow_id) !== '';
    }

    private function now(): Carbon
    {
        return Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format(\DateTimeInterface::ATOM);
    }
}
