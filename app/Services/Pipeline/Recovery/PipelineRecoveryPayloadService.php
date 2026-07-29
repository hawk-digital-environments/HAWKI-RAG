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
            'task_id' => $job->task_id,
            'dataset_id' => $task?->dataset_id,
            'job_id' => $job->job_id,
            'job_type' => $job->job_type,
            'source_id' => $job->source_id,
            'source_url' => $job->source_url,
            'local_path' => $job->local_path,
            'content_hash' => $job->content_hash,
            'temporal_workflow_id' => $job->temporal_workflow_id,
            'temporal_run_id' => $job->temporal_run_id,
            'temporal_schedule_id' => $job->temporal_schedule_id,
            'index_status' => $job->index_status,
            'status' => $job->status,
            'error_message' => $job->error_message,
            'retry_count' => (int) ($metadata['retry_count'] ?? 0),
            'timestamp' => ($job->finished_at ?? $job->updated_at ?? $job->created_at)?->format(DATE_ATOM),
            'last_recovery_event' => is_array($metadata['last_recovery_event'] ?? null) ? $metadata['last_recovery_event'] : null,
        ];
    }
}
