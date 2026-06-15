<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Recovery;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use App\Services\Pipeline\Repositories\PipelineJobRecoveryRepository;
use App\Services\Pipeline\Repositories\PipelineJobStateMutationRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Repositories\PipelineTransactionRepository;
use App\Services\Pipeline\Tasks\IngestSourceWorkflowPayloadFactory;
use App\Services\Pipeline\Tasks\PipelineTaskService;
use App\Services\Pipeline\Clients\PythonTemporalBridgeClient;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineRecoveryAttemptService
{
    public function __construct(
        private PipelineTaskService $tasks,
        private PipelineRecoveryMetadataService $metadata,
        private PipelineRecoveryFailedJobPresenter $presenter,
        private IngestSourceWorkflowPayloadFactory $workflowPayloads,
        private IngestionSourceRepository $ingestionSources,
        private PipelineJobRecoveryRepository $jobRecovery,
        private PipelineJobStateMutationRepository $jobStates,
        private PipelineTaskRepository $taskRepository,
        private PipelineTransactionRepository $transactions,
        private PythonTemporalBridgeClient $temporalBridge,
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
        /** @var PipelineTask $preparedTask */
        $preparedTask = $prepared['task'];
        try {
            $started = $this->startTemporalRetry(
                $preparedTask,
                $preparedJob,
                is_array($preparedJob->metadata) ? $preparedJob->metadata : [],
            );
            $this->tasks->recalculateTaskStatus((string) $started->task_id);
            $this->logger->info('pipeline.recovery.temporal_retry_started', [
                'scope' => $scope,
                'scope_id' => $scopeId,
                'task_id' => $started->task_id,
                'job_id' => $started->job_id,
                'workflow_id' => $started->temporal_workflow_id,
                'run_id' => $started->temporal_run_id,
            ]);

            return array_merge($this->presenter->present($started), [
                'result' => 'retried',
                'message' => 'Temporal workflow retry started.',
                'temporalWorkflowId' => $started->temporal_workflow_id,
                'temporalRunId' => $started->temporal_run_id,
            ]);
        } catch (\Throwable $error) {
            $failed = $this->markPublishFailed($preparedJob, $error);

            return array_merge($this->presenter->present($failed), [
                'result' => 'failed',
                'message' => 'Temporal workflow retry could not be started: '.$error->getMessage(),
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

            if ($locked->job_type !== PipelineJob::TYPE_INGEST || ! is_string($locked->source_id) || trim($locked->source_id) === '') {
                return [
                    'result' => 'skipped',
                    'jobId' => $locked->job_id,
                    'taskId' => $locked->task_id,
                    'message' => 'Only Temporal source ingestion jobs with a source_id can be retried here.',
                ];
            }

            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
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
                'recoveryEvent' => $recoveryEvent,
            ];
        });
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function startTemporalRetry(PipelineTask $task, PipelineJob $job, array $metadata): PipelineJob
    {
        $source = $this->ingestionSources->findBySourceId((string) $job->source_id);
        if (! $source) {
            throw new \RuntimeException("Ingestion source {$job->source_id} was not found.");
        }

        $source = $this->ingestionSources->upsertStarting($source->source_id, [
            'source_url' => $source->source_url,
            'task_id' => $task->task_id,
            'dataset_id' => $task->dataset_id,
            'refresh_cadence' => $source->refresh_cadence,
            'raw_storage_path' => $source->raw_storage_path,
            'markdown_storage_path' => $source->markdown_storage_path,
            'metadata' => array_merge($source->metadata ?? [], [
                'retried_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
            ]),
        ]);

        $workflowId = is_string($job->temporal_workflow_id) && trim($job->temporal_workflow_id) !== ''
            ? $job->temporal_workflow_id
            : $this->workflowPayloads->workflowId($source->source_id);
        $execution = $this->temporalBridge->startIngestWorkflow(
            $this->workflowPayloads->input($task, $job, $source),
            $workflowId,
        );
        $scheduleId = is_string($job->temporal_schedule_id) ? $job->temporal_schedule_id : null;
        $metadata['temporal'] = array_filter([
            'workflow_id' => $execution->workflowId,
            'run_id' => $execution->runId,
            'schedule_id' => $scheduleId,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $this->ingestionSources->markWorkflowStarted($source, $execution->workflowId, $execution->runId, $scheduleId);

        return $this->jobStates->markTemporalStarted($job, $execution->workflowId, $execution->runId, $scheduleId, $metadata);
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
