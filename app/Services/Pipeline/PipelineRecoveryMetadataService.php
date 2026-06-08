<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Str;

#[Singleton]
readonly class PipelineRecoveryMetadataService
{
    /**
     * @return array<string, mixed>
     */
    public function recoveryEvent(PipelineJob $job, string $scope, ?string $scopeId, int $retryCount): array
    {
        return [
            'event_id' => 'recovery_' . Str::uuid()->toString(),
            'event' => 'job.recovery_requested',
            'scope' => $scope,
            'scope_id' => $scopeId,
            'task_id' => $job->task_id,
            'job_id' => $job->job_id,
            'retry_count' => $retryCount,
            'idempotency_key' => $this->idempotencyKey($job),
            'at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $recoveryEvent
     * @return array<string, mixed>
     */
    public function queuedJobMetadata(array $metadata, array $recoveryEvent): array
    {
        $metadata['retry_count'] = (int) ($recoveryEvent['retry_count'] ?? 0);
        $metadata['retried_at'] = $recoveryEvent['at'] ?? now()->toIso8601String();
        $metadata['last_recovery_event'] = $recoveryEvent;
        $metadata['recovery_events'] = array_merge(
            is_array($metadata['recovery_events'] ?? null) ? $metadata['recovery_events'] : [],
            [$recoveryEvent],
        );
        $metadata['events'] = array_merge(
            is_array($metadata['events'] ?? null) ? $metadata['events'] : [],
            [[
                'event_type' => 'job.recovery_requested',
                'event_id' => $recoveryEvent['event_id'] ?? null,
                'status' => PipelineJob::STATUS_QUEUED,
                'at' => $recoveryEvent['at'] ?? null,
            ]],
        );

        return $metadata;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function taskRecoveryMetadata(PipelineTask $task, array $event): array
    {
        $metadata = is_array($task->metadata) ? $task->metadata : [];
        $metadata['last_recovery_event'] = $event;
        $metadata['recovery_events'] = array_merge(
            is_array($metadata['recovery_events'] ?? null) ? $metadata['recovery_events'] : [],
            [$event],
        );

        return $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function publishFailedJobMetadata(PipelineJob $job, \Throwable $error): array
    {
        $metadata = is_array($job->metadata) ? $job->metadata : [];
        $event = [
            'event_id' => 'recovery_' . Str::uuid()->toString(),
            'event' => 'recovery_publish_failed',
            'at' => now()->toIso8601String(),
            'error_type' => class_basename($error),
            'error_message' => $error->getMessage(),
        ];
        $metadata['last_recovery_event'] = $event;
        $metadata['recovery_events'] = array_merge(
            is_array($metadata['recovery_events'] ?? null) ? $metadata['recovery_events'] : [],
            [$event],
        );

        return $metadata;
    }

    public function idempotencyKey(PipelineJob $job): string
    {
        return hash('sha256', implode('|', [
            $job->task_id,
            $job->job_id,
            $job->content_hash,
            $job->local_path,
            $job->source_url,
        ]));
    }
}
