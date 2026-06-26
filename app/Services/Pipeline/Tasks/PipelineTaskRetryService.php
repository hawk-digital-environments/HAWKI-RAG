<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use App\Services\Pipeline\Repositories\PipelineJobStateMutationRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Repositories\Queries\FailedPipelineJobsQuery;
use App\Services\Pipeline\Clients\PythonTemporalBridgeClient;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineTaskRetryService
{
    public function __construct(
        private PipelineTaskMetadataService $metadata,
        private IngestSourceWorkflowPayloadFactory $workflowPayloads,
        private IngestionSourceRepository $ingestionSources,
        private PipelineTaskRepository $taskRepository,
        private FailedPipelineJobsQuery $failedJobs,
        private PipelineJobStateMutationRepository $jobStates,
        private PipelineTaskStatusRefresher $refresher,
        private PythonTemporalBridgeClient $temporalBridge,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    public function retryFailedJobs(string $taskId): ?PipelineTask
    {
        $task = $this->taskRepository->findByTaskId($taskId);
        if (! $task) {
            return null;
        }

        $jobs = $this->failedJobs->forRetry($task);

        foreach ($jobs as $job) {
            $metadata = $job->metadata ?? [];
            $metadata['retry_count'] = (int) ($metadata['retry_count'] ?? 0) + 1;
            $metadata['retried_at'] = $this->timestamp();

            $this->restartTemporalWorkflow($task, $job, $metadata);
        }

        if ($jobs->isNotEmpty()) {
            $task = $this->taskRepository->markFailedJobsRetried(
                $task,
                $this->metadata->appendEvent($task, 'failed_jobs_retried'),
            );
        }

        return $this->refresher->recalculate($task);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function restartTemporalWorkflow(PipelineTask $task, PipelineJob $job, array $metadata): void
    {
        if (! is_string($job->source_id) || trim($job->source_id) === '') {
            return;
        }

        $source = $this->ingestionSources->findBySourceId($job->source_id);
        if (! $source) {
            return;
        }

        $workflowId = $this->retryWorkflowId($job, $source->source_id, (int) ($metadata['retry_count'] ?? 1));

        $source = $this->ingestionSources->upsertStarting($source->source_id, [
            'source_url' => $source->source_url,
            'task_id' => $task->task_id,
            'dataset_id' => $task->dataset_id,
            'refresh_cadence' => $source->refresh_cadence,
            'raw_storage_path' => $source->raw_storage_path,
            'markdown_storage_path' => $source->markdown_storage_path,
            'metadata' => array_merge($source->metadata ?? [], [
                'retried_at' => $this->timestamp(),
            ]),
        ]);

        $workflowInput = $this->workflowPayloads->input($task, $job, $source);
        $execution = $this->temporalBridge->startIngestWorkflow($workflowInput, $workflowId);
        $metadata['temporal'] = array_filter([
            'workflow_id' => $execution->workflowId,
            'run_id' => $execution->runId,
            'schedule_id' => $job->temporal_schedule_id,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $this->ingestionSources->markWorkflowStarted($source, $execution->workflowId, $execution->runId, $job->temporal_schedule_id);
        $this->jobStates->markTemporalStarted(
            $job,
            $execution->workflowId,
            $execution->runId,
            is_string($job->temporal_schedule_id) ? $job->temporal_schedule_id : null,
            $metadata,
        );
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format(\DateTimeInterface::ATOM);
    }

    private function retryWorkflowId(PipelineJob $job, string $sourceId, int $retryCount): string
    {
        $baseWorkflowId = is_string($job->temporal_workflow_id) && trim($job->temporal_workflow_id) !== ''
            ? $job->temporal_workflow_id
            : $this->workflowPayloads->workflowId($sourceId);

        $safeJobId = preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string) $job->job_id) ?: 'job';

        return sprintf('%s-retry-%d-%s', $baseWorkflowId, max(1, $retryCount), $safeJobId);
    }
}
