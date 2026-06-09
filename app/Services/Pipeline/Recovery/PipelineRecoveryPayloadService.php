<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Recovery;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Events\PipelineEventConfig;
use App\Services\Pipeline\Tasks\PipelineTaskEventPayloadService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineRecoveryPayloadService
{
    public function __construct(
        private PipelineRecoveryMetadataService $metadata,
        private PipelineTaskEventPayloadService $eventPayloads,
        private PipelineEventConfig $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function failedJob(PipelineJob $job, ?PipelineTask $task): array
    {
        $metadata = is_array($job->metadata) ? $job->metadata : [];

        return [
            'taskId' => $job->task_id,
            'datasetId' => $task?->dataset_id,
            'jobId' => $job->job_id,
            'jobType' => $job->job_type,
            'sourceUrl' => $job->source_url,
            'localPath' => $job->local_path,
            'contentHash' => $job->content_hash,
            'status' => $job->status,
            'errorMessage' => $job->error_message,
            'retryCount' => (int) ($metadata['retry_count'] ?? 0),
            'timestamp' => ($job->finished_at ?? $job->updated_at ?? $job->created_at)?->format(DATE_ATOM),
            'lastRecoveryEvent' => is_array($metadata['last_recovery_event'] ?? null) ? $metadata['last_recovery_event'] : null,
        ];
    }

    public function retryEventType(PipelineJob $job): ?string
    {
        return $this->eventPayloads->retryEventType($job);
    }

    /**
     * @param  array<string, mixed>  $recoveryEvent
     * @return array<string, mixed>
     */
    public function retryEvent(
        PipelineTask $task,
        PipelineJob $job,
        string $eventType,
        array $recoveryEvent,
    ): array {
        $payload = $this->eventPayloads->forJob($task, $job, $eventType);
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        return array_merge($payload, [
            'event_type' => $eventType,
            'status' => PipelineJob::STATUS_QUEUED,
            'retry_count' => (int) ($metadata['retry_count'] ?? 0),
            'max_retries' => (int) ($metadata['max_retries'] ?? $this->config->maxRetries()),
            'metadata' => array_merge($metadata, [
                'recovery_event' => $recoveryEvent,
                'idempotency_key' => $this->metadata->idempotencyKey($job),
            ]),
        ]);
    }
}
