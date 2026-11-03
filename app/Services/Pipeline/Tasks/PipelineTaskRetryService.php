<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\PipelineRecoveryWorkflowService;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Repositories\Queries\FailedPipelineJobsQuery;
use App\Services\TextIngestion\TextIngestionRecoveryService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineTaskRetryService
{
    public function __construct(
        private PipelineTaskMetadataService $metadata,
        private IngestionSourceRepository $ingestionSources,
        private PipelineTaskRepository $taskRepository,
        private FailedPipelineJobsQuery $failedJobs,
        private PipelineTaskStatusRefresher $refresher,
        private TextIngestionRecoveryService $textIngestionRecovery,
        private PipelineRecoveryWorkflowService $workflows,
    ) {}

    public function retryFailedJobs(string $taskId): ?PipelineTask
    {
        $task = $this->taskRepository->findByTaskId($taskId);
        if (! $task) {
            return null;
        }

        $jobs = $this->failedJobs->forRetry($task);

        foreach ($jobs as $job) {
            $this->restartTemporalWorkflow($task, $job);
        }

        if ($jobs->isNotEmpty()) {
            $task = $this->taskRepository->markFailedJobsRetried(
                $task,
                $this->metadata->appendEvent($task, 'failed_jobs_retried'),
            );
        }

        return $this->refresher->recalculate($task);
    }

    private function restartTemporalWorkflow(PipelineTask $task, PipelineJob $job): void
    {
        if (! is_string($job->source_id) || trim($job->source_id) === '') {
            return;
        }

        $source = $this->ingestionSources->findBySourceId($job->source_id);
        if (! $source) {
            return;
        }

        $directText = $this->textIngestionRecovery->supports($job, $source);
        if ($directText && $this->textIngestionRecovery->isDeletionTombstone($source)) {
            return;
        }

        $prepared = $this->workflows->reserve($job);
        if ($prepared) {
            $this->workflows->start($task, $prepared);
        }
    }
}
