<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Recovery;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\PipelineRecoveryWorkflowService;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use App\Services\Pipeline\Repositories\PipelineJobRecoveryRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Repositories\PipelineTransactionRepository;
use App\Services\Pipeline\Tasks\PipelineTaskService;
use App\Services\TextIngestion\TextIngestionRecoveryService;
use Illuminate\Container\Attributes\Singleton;
use Psr\Log\LoggerInterface;

#[Singleton]
readonly class PipelineRecoveryAttemptService
{
    public function __construct(
        private PipelineTaskService $tasks,
        private PipelineRecoveryMetadataService $metadata,
        private PipelineRecoveryFailedJobPresenter $presenter,
        private IngestionSourceRepository $ingestionSources,
        private PipelineJobRecoveryRepository $jobRecovery,
        private PipelineTaskRepository $taskRepository,
        private PipelineTransactionRepository $transactions,
        private TextIngestionRecoveryService $textIngestionRecovery,
        private LoggerInterface $logger,
        private PipelineRecoveryWorkflowService $workflows,
    ) {}

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
            $started = $this->workflows->start($preparedTask, $preparedJob);
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
            $failed = $this->workflows->failed($preparedJob, $error);
            $this->tasks->recalculateTaskStatus((string) $failed->task_id);

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

            if ($locked->status !== PipelineJob::STATUS_FAILED && ! $this->workflows->hasPending($locked)) {
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

            $source = $this->ingestionSources->lockBySourceId($locked->source_id);
            if (
                $source
                && $this->textIngestionRecovery->supports($locked, $source)
                && $this->textIngestionRecovery->isDeletionTombstone($source)
            ) {
                return [
                    'result' => 'skipped',
                    'jobId' => $locked->job_id,
                    'taskId' => $locked->task_id,
                    'message' => 'Deleted direct-text ingestions cannot be recovered.',
                ];
            }

            $resuming = $this->workflows->hasPending($locked);
            $locked = $this->workflows->reserve($locked);
            $metadata = $locked->metadata ?? [];
            $recoveryEvent = $resuming
                ? ($metadata['last_recovery_event'] ?? [])
                : $this->metadata->recoveryEvent($locked, $scope, $scopeId, $metadata['retry_count']);

            $locked = $this->jobRecovery->markRecoveryQueued($locked, $resuming ? $metadata : $this->metadata->queuedJobMetadata($metadata, $recoveryEvent));
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
}
