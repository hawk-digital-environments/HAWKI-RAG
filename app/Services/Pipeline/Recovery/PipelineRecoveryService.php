<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Recovery;

use App\Models\PipelineJob;
use App\Services\Pipeline\Events\PipelineEventBus;
use App\Services\Pipeline\Repositories\PipelineJobRecoveryRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Repositories\PipelineTransactionRepository;
use App\Services\Pipeline\Repositories\Queries\ActivePipelineJobsQuery;
use App\Services\Pipeline\Repositories\Queries\FailedPipelineJobsQuery;
use App\Services\Pipeline\Tasks\PipelineTaskService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineRecoveryService
{
    public function __construct(
        private readonly PipelineEventBus $events,
        private readonly PipelineTaskService $tasks,
        private readonly PipelineRecoveryInputNormalizer $input,
        private readonly PipelineRecoveryMetadataService $metadata,
        private readonly PipelineRecoveryPayloadService $payloads,
        private readonly ActivePipelineJobsQuery $activeJobs,
        private readonly FailedPipelineJobsQuery $failedJobs,
        private readonly PipelineJobRecoveryRepository $jobRecovery,
        private readonly PipelineTaskRepository $taskRepository,
        private readonly PipelineTransactionRepository $transactions,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock = new Clock(),
    ) {}

    public function failedJobs(array $filters = []): array
    {
        $filters = $this->input->filters($filters);

        return $this->failedJobs
            ->forRecoveryList($filters['task_id'], $filters['dataset_id'], $filters['limit'])
            ->map(fn (PipelineJob $job): array => $this->failedJobPayload($job))
            ->all();
    }

    public function retrySelected(array $jobIds): array
    {
        $jobIds = $this->input->jobIds($jobIds);

        if ($jobIds === []) {
            return $this->emptyResult('selected');
        }

        $jobs = $this->activeJobs->findByJobIds($jobIds);

        return $this->retryJobs($jobs, 'selected');
    }

    public function retryJob(string $jobId): array
    {
        $job = $this->activeJobs->findByJobId($jobId);

        return $this->retryJobs($job ? collect([$job]) : collect(), 'job', $jobId);
    }

    public function retryAll(): array
    {
        return $this->retryJobs($this->failedJobs->forRecovery(), 'all');
    }

    public function retryTask(string $taskId): array
    {
        return $this->retryJobs($this->failedJobs->forRecovery($taskId), 'task', $taskId);
    }

    public function retryDataset(string $datasetId): array
    {
        return $this->retryJobs($this->failedJobs->forRecovery(null, $datasetId), 'dataset', $datasetId);
    }

    private function retryJobs(Collection $jobs, string $scope, ?string $scopeId = null): array
    {
        $result = $this->emptyResult($scope, $scopeId);

        foreach ($jobs as $job) {
            $attempt = $this->retryOne($job, $scope, $scopeId);
            $result['jobs'][] = $attempt;
            $result['attempted']++;
            $result[$attempt['result']] = ($result[$attempt['result']] ?? 0) + 1;
        }

        return $result;
    }

    private function retryOne(PipelineJob $job, string $scope, ?string $scopeId): array
    {
        $prepared = $this->transactions->run(function () use ($job, $scope, $scopeId): array {
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

            return array_merge($this->failedJobPayload($preparedJob), [
                'result' => 'retried',
                'message' => 'Recovery retry queued.',
                'publishedEventType' => $published['event_type'] ?? null,
            ]);
        } catch (\Throwable $error) {
            $failed = $this->markPublishFailed($preparedJob, $error);

            return array_merge($this->failedJobPayload($failed), [
                'result' => 'failed',
                'message' => 'Recovery retry could not be published: '.$error->getMessage(),
            ]);
        }
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

    private function failedJobPayload(PipelineJob $job): array
    {
        $task = $job->relationLoaded('task')
            ? $job->task
            : $this->taskRepository->findByTaskId((string) $job->task_id);

        return $this->payloads->failedJob($job, $task);
    }

    private function emptyResult(string $scope, ?string $scopeId = null): array
    {
        return [
            'scope' => $scope,
            'scopeId' => $scopeId,
            'attempted' => 0,
            'retried' => 0,
            'skipped' => 0,
            'failed' => 0,
            'jobs' => [],
        ];
    }

    private function now(): Carbon
    {
        return Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
