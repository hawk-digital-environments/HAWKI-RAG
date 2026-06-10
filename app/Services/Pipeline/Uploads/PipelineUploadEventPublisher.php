<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Uploads;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventBus;
use App\Services\Pipeline\Repositories\PipelineJobStateMutationRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Values\PipelineUploadPublishOutcome;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineUploadEventPublisher
{
    public function __construct(
        private PipelineEventBus $events,
        private PipelineJobStateMutationRepository $jobStates,
        private PipelineTaskRepository $taskRepository,
        private LoggerInterface $logger,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function publish(PipelineTask $task, PipelineJob $job, array $payload): PipelineUploadPublishOutcome
    {
        try {
            $this->events->publish(PipelineEvent::FILE_DISCOVERED, $payload);

            return PipelineUploadPublishOutcome::published($task, $job);
        } catch (\Throwable $exception) {
            $failedAt = $this->now();
            $job = $this->jobStates->markFailed(
                $job,
                'Unable to publish file.discovered event: '.$exception->getMessage(),
                $failedAt,
            );
            $task = $this->taskRepository->markFailed($task, $failedAt);

            $this->logger->warning('Pipeline controller file upload event publish failed.', [
                'task_id' => $task->task_id,
                'job_id' => $job->job_id,
                'error' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return PipelineUploadPublishOutcome::failed($task, $job, $exception);
        }
    }

    private function now(): Carbon
    {
        return Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
