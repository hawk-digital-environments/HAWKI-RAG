<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Repositories\PipelineJobRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineTaskRetryService
{
    public function __construct(
        private PipelineTaskEventPayloadService $eventPayloads,
        private PipelineTaskMetadataService $metadata,
        private PipelineTaskRepository $taskRepository,
        private PipelineJobRepository $jobRepository,
        private PipelineTaskEventPublisher $publisher,
        private PipelineTaskStatusRefresher $refresher,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    public function retryFailedJobs(string $taskId): ?PipelineTask
    {
        $task = $this->taskRepository->findByTaskId($taskId);
        if (! $task) {
            return null;
        }

        $jobs = $this->jobRepository->failedForRetry($task);

        foreach ($jobs as $job) {
            $metadata = $job->metadata ?? [];
            $metadata['retry_count'] = (int) ($metadata['retry_count'] ?? 0) + 1;
            $metadata['retried_at'] = $this->timestamp();

            $job = $this->jobRepository->markQueuedForRetry($job, $metadata);
            $this->publishRetryEventForJob($task, $job);
        }

        if ($jobs->isNotEmpty()) {
            $task = $this->taskRepository->markFailedJobsRetried(
                $task,
                $this->metadata->appendEvent($task, 'failed_jobs_retried'),
            );
        }

        return $this->refresher->recalculate($task);
    }

    private function publishRetryEventForJob(PipelineTask $task, PipelineJob $job): void
    {
        $eventType = $this->eventPayloads->retryEventType($job);
        if ($eventType === null) {
            return;
        }

        $this->publisher->publish($eventType, $this->eventPayloads->forJob($task, $job, $eventType));
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format(\DateTimeInterface::ATOM);
    }
}
