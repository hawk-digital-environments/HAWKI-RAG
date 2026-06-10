<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Recovery;

use App\Models\PipelineJob;
use App\Services\Pipeline\Events\PipelineEventBus;
use App\Services\Pipeline\Repositories\PipelineJobRecoveryRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Repositories\PipelineTransactionRepository;
use App\Services\Pipeline\Tasks\PipelineTaskService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineRecoveryAttemptService
{
    public function __construct(
        private PipelineEventBus $events,
        private PipelineTaskService $tasks,
        private PipelineRecoveryMetadataService $metadata,
        private PipelineRecoveryPayloadService $payloads,
        private PipelineRecoveryFailedJobPresenter $presenter,
        private PipelineJobRecoveryRepository $jobRecovery,
        private PipelineTaskRepository $taskRepository,
        private PipelineTransactionRepository $transactions,
        private LoggerInterface $logger,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function retry(PipelineJob $job, string $scope, ?string $scopeId): array
    {
        $prepared = $this->prepare($job, $scope, $scopeId);

        if (($prepared['result'] ?? null) !== 'prepared') {
            return $prepared;
        }

        /** @var PipelineJob $preparedJob */
        $preparedJob = $prepared['job'];
        try {
            $published = $this->events->publishRecoveryRetry(
                $prepared['event'],
                sprintf('operator %s recovery%s', $scope, $scopeId ? " {$scopeId}" : ''),
            );
            $this->tasks->recalculateTaskStatus((string) $preparedJob->task_id);
            $this->logger->info('pipeline.recovery.retry_queued', [
                'scope' => $scope,
                'scope_id' => $scopeId,
                'task_id' => $preparedJob->task_id,
                'job_id' => $preparedJob->job_id,
                'event_type' => $published['event_type'] ?? null,
                'retry_count' => $published['retry_count'] ?? null,
            ]);

            return array_merge($this->presenter->present($preparedJob), [
                'result' => 'retried',
                'message' => 'Recovery retry queued.',
                'publishedEventType' => $published['event_type'] ?? null,
            ]);
        } catch (\Throwable $error) {
            $failed = $this->markPublishFailed($preparedJob, $error);

            return array_merge($this->presenter->present($failed), [
                'result' => 'failed',
                'message' => 'Recovery retry could not be published: '.$error->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function prepare(PipelineJob $job, string $scope, ?string $scopeId): array
    {
        return $this->transactions->run(function () use ($job, $scope, $scopeId): array {
            $locked = $this->jobRecovery->lockForRecovery($job);

            if (! $locked) {
                return [
                    'result' => 'skipped',
                    'jobId' => $job->job_id,
                    'message' => 'Job no longer exists.',
                ];
            }

            if ($locked->status !== PipelineJob::STATUS_FAILED) {
                return [
                    'result' => 'skipped',
                    'jobId' => $locked->job_id,
                    'taskId' => $locked->task_id,
                    'message' => "Job is {$locked->status}; only failed jobs are retried.",
                ];
            }

            $task = $this->taskRepository->findByTaskId((string) $locked->task_id);
            if (! $task) {
                return [
                    'result' => 'failed',
                    'jobId' => $locked->job_id,
                    'taskId' => $locked->task_id,
                    'message' => 'Parent task was not found.',
                ];
            }

            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $eventType = $this->payloads->retryEventType($locked);
            if ($eventType === null) {
                return [
                    'result' => 'skipped',
                    'jobId' => $locked->job_id,
                    'taskId' => $locked->task_id,
                    'message' => "Job type {$locked->job_type} cannot be retried through RabbitMQ recovery.",
                ];
            }

            $retryCount = (int) ($metadata['retry_count'] ?? 0) + 1;
            $recoveryEvent = $this->metadata->recoveryEvent($locked, $scope, $scopeId, $retryCount);

            $locked = $this->jobRecovery->markRecoveryQueued($locked, $this->metadata->queuedJobMetadata($metadata, $recoveryEvent));
            $task = $this->taskRepository->markRecoveryRunning(
                $task,
                $this->metadata->taskRecoveryMetadata($task, $recoveryEvent),
            );

            return [
                'result' => 'prepared',
                'job' => $locked,
                'task' => $task,
                'event' => $this->payloads->retryEvent($task, $locked, $eventType, $recoveryEvent),
                'recoveryEvent' => $recoveryEvent,
            ];
        });
    }

    private function markPublishFailed(PipelineJob $job, \Throwable $error): PipelineJob
    {
        $job = $this->jobRecovery->markRecoveryPublishFailed(
            $job,
            $error->getMessage(),
            $this->now(),
            $this->metadata->publishFailedJobMetadata($job, $error),
        );

        $this->tasks->recalculateTaskStatus((string) $job->task_id);

        return $job;
    }

    private function now(): Carbon
    {
        return Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
