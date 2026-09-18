<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Status;

use App\Models\PipelineJob;
use App\Services\Pipeline\Clients\PythonTemporalBridgeClient;
use App\Services\Pipeline\Repositories\PipelineJobRecoveryRepository;
use App\Services\Pipeline\Repositories\PipelineJobStateMutationRepository;
use App\Services\Pipeline\Repositories\PipelineStageStateRepository;
use App\Services\Pipeline\Repositories\PipelineTransactionRepository;
use App\Services\Pipeline\State\PipelineStateService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineWorkflowStatusSynchronizer
{
    public function __construct(
        private PythonTemporalBridgeClient $bridge,
        private PipelineJobRecoveryRepository $jobs,
        private PipelineJobStateMutationRepository $jobStates,
        private PipelineStageStateRepository $stages,
        private PipelineTransactionRepository $transactions,
        private PipelineStateService $state,
        private LoggerInterface $logger,
        private ClockInterface $clock = new Clock,
    ) {}

    public function sync(PipelineJob $job): PipelineJob
    {
        if (! in_array($job->status, PipelineJob::ACTIVE_STATUSES, true)
            || ! $job->temporal_workflow_id || ! $job->temporal_run_id) {
            return $job;
        }

        try {
            $execution = $this->bridge->workflowStatus($job->temporal_workflow_id, $job->temporal_run_id);
        } catch (\Throwable $error) {
            // A bridge outage is not evidence that a workflow failed.
            $this->logger->warning('Pipeline workflow status could not be refreshed.', [
                'job_id' => $job->job_id, 'exception' => $error,
            ]);

            return $job;
        }

        if (($execution['workflow_id'] ?? null) !== $job->temporal_workflow_id
            || ($execution['run_id'] ?? null) !== $job->temporal_run_id
            || ! in_array($execution['status'] ?? null, ['FAILED', 'TIMED_OUT', 'TERMINATED', 'CANCELED'], true)) {
            return $job;
        }

        return $this->transactions->run(function () use ($job, $execution): PipelineJob {
            $current = $this->jobs->lockForRecovery($job);
            // A retry or callback may have advanced the job during the HTTP request.
            if (! $current || ! in_array($current->status, PipelineJob::ACTIVE_STATUSES, true)
                || $current->temporal_workflow_id !== $job->temporal_workflow_id
                || $current->temporal_run_id !== $job->temporal_run_id) {
                return $current ?? $job;
            }

            $message = (string) (($execution['error'] ?? null) ?: 'Temporal workflow '.$execution['status'].'.');
            $stages = $this->stages->forPipelineJob($current)->keyBy('stage');
            $stageNames = $stages->isEmpty() ? ['scrape'] : ['scrape', 'convert', 'ingest'];
            foreach ($stageNames as $stage) {
                if ($stages->isNotEmpty() && ! $stages->has($stage)) {
                    continue;
                }
                if (! in_array($stages->get($stage)?->status, ['completed', 'skipped'], true)) {
                    $this->state->failStage($current->job_id, $stage, [
                        'errors' => [$message],
                        'job_status' => PipelineJob::STATUS_FAILED,
                    ]);
                    break;
                }
            }

            return $this->jobStates->markFailed(
                $current,
                $message,
                Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now())),
            );
        });
    }
}
