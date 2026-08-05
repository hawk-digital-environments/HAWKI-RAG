<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Services\Pipeline\Values\PipelineWorkerEvent;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;

#[Singleton]
readonly class PipelineScheduledRunRepository
{
    public function adopt(
        PipelineJob $job,
        IngestionSource $source,
        PipelineWorkerEvent $event,
        Carbon $adoptedAt,
    ): void {
        $jobMetadata = is_array($job->metadata) ? $job->metadata : [];
        $jobMetadata['temporal'] = [
            'workflow_id' => $event->workflowId,
            'run_id' => $event->runId,
            'schedule_id' => $job->temporal_schedule_id,
        ];
        $job->forceFill([
            'status' => PipelineJob::STATUS_RUNNING,
            'current_stage' => 'temporal.scheduled_run_started',
            'temporal_run_id' => $event->runId,
            'index_status' => IngestionSource::STATUS_RUNNING,
            'error_message' => null,
            'total_documents' => 0,
            'processed_documents' => 0,
            'failed_documents' => 0,
            'skipped_documents' => 0,
            'completed_at' => null,
            'finished_at' => null,
            'metadata' => $jobMetadata,
        ])->save();

        $sourceMetadata = is_array($source->metadata) ? $source->metadata : [];
        unset($sourceMetadata['error']);
        $sourceMetadata['temporal'] = [
            'workflow_id' => $event->workflowId,
            'run_id' => $event->runId,
            'schedule_id' => $source->temporal_schedule_id,
        ];
        $source->forceFill([
            'index_status' => IngestionSource::STATUS_RUNNING,
            'metadata' => $sourceMetadata,
        ])->save();

        PipelineStageState::query()
            ->where('pipeline_job_id', $job->id)
            ->lockForUpdate()
            ->get()
            ->each(function (PipelineStageState $state) use ($adoptedAt): void {
                $state->forceFill([
                    'status' => 'pending',
                    'counts' => [
                        'total' => 0,
                        'processed' => 0,
                        'failed' => 0,
                        'skipped' => 0,
                    ],
                    'metadata' => [],
                    'errors' => [],
                    'warnings' => [],
                    'retry_count' => 0,
                    'started_at' => null,
                    'completed_at' => null,
                    'failed_at' => null,
                    'last_transition_at' => $adoptedAt,
                ])->save();
            });
    }
}
