<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\IngestionSource;
use App\Models\PipelineJob;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;

#[Singleton]
readonly class PipelineJobStateMutationRepository
{
    public function markFailed(PipelineJob $job, string $message, Carbon $failedAt): PipelineJob
    {
        $job->forceFill([
            'status' => PipelineJob::STATUS_FAILED,
            'current_stage' => 'temporal.workflow_failed',
            'index_status' => 'failed',
            'error_message' => $message,
            'finished_at' => $failedAt,
        ])->save();

        return $job->refresh();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function markTemporalStarted(
        PipelineJob $job,
        string $workflowId,
        ?string $runId,
        ?string $scheduleId,
        array $metadata,
    ): PipelineJob {
        $job->forceFill([
            'status' => PipelineJob::STATUS_RUNNING,
            'current_stage' => 'temporal.workflow_started',
            'temporal_workflow_id' => $workflowId,
            'temporal_run_id' => $runId,
            'temporal_schedule_id' => $scheduleId,
            'index_status' => 'running',
            'error_message' => null,
            'completed_at' => null,
            'finished_at' => null,
            'metadata' => $metadata,
        ])->save();

        return $job->refresh();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function confirmTemporalStarted(
        PipelineJob $job,
        string $workflowId,
        ?string $runId,
        array $metadata,
    ): PipelineJob {
        $current = PipelineJob::query()
            ->whereKey($job->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $current->temporal_workflow_id
            && ! hash_equals((string) $current->temporal_workflow_id, $workflowId)
        ) {
            throw new \RuntimeException('Temporal workflow confirmation does not match the pipeline job.');
        }

        if (
            $current->index_status === IngestionSource::STATUS_READY
            || $current->status === PipelineJob::STATUS_COMPLETED
        ) {
            return $current;
        }

        $runChanged = $current->temporal_run_id
            && $runId
            && ! hash_equals((string) $current->temporal_run_id, $runId);
        if (
            $current->current_stage === 'temporal.workflow_starting'
            || $current->status === PipelineJob::STATUS_FAILED
            || $current->index_status === IngestionSource::STATUS_FAILED
            || $runChanged
        ) {
            return $this->markTemporalStarted(
                $current,
                $workflowId,
                $runId,
                null,
                $metadata,
            );
        }

        if (! $current->temporal_run_id && $runId) {
            $current->forceFill(['temporal_run_id' => $runId])->save();
        }

        return $current->refresh();
    }

    public function adoptInitialTemporalRun(PipelineJob $job, string $runId): PipelineJob
    {
        if ($job->temporal_run_id) {
            return $job;
        }

        $job->forceFill(['temporal_run_id' => $runId])->save();

        return $job->refresh();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function markTemporalCancellationRequested(PipelineJob $job, Carbon $cancelledAt, array $metadata): PipelineJob
    {
        $job->forceFill([
            'status' => PipelineJob::STATUS_FAILED,
            'current_stage' => 'temporal.cancel_requested',
            'index_status' => 'cancelled',
            'error_message' => 'Temporal workflow cancellation requested.',
            'finished_at' => $cancelledAt,
            'metadata' => $metadata,
        ])->save();

        return $job->refresh();
    }
}
