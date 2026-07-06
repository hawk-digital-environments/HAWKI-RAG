<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Recovery;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineRecoveryPayloadService
{
    /**
     * @return array<string, mixed>
     */
    public function failedJob(PipelineJob $job, ?PipelineTask $task): array
    {
        $metadata = is_array($job->metadata) ? $job->metadata : [];

        return [
            'taskId' => $job->task_id,
            'heapId' => $task?->dataset_id,
            'datasetId' => $task?->dataset_id,
            'jobId' => $job->job_id,
            'jobType' => $job->job_type,
            'sourceId' => $job->source_id,
            'sourceUrl' => $job->source_url,
            'localPath' => $job->local_path,
            'contentHash' => $job->content_hash,
            'temporalWorkflowId' => $job->temporal_workflow_id,
            'temporalRunId' => $job->temporal_run_id,
            'temporalScheduleId' => $job->temporal_schedule_id,
            'indexStatus' => $job->index_status,
            'status' => $job->status,
            'errorMessage' => $job->error_message,
            'retryCount' => (int) ($metadata['retry_count'] ?? 0),
            'timestamp' => ($job->finished_at ?? $job->updated_at ?? $job->created_at)?->format(DATE_ATOM),
            'lastRecoveryEvent' => is_array($metadata['last_recovery_event'] ?? null) ? $metadata['last_recovery_event'] : null,
        ];
    }
}
