<?php

declare(strict_types=1);

namespace App\Services\Pipeline\State;

use App\Models\PipelineJob;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineStageAttributeNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public function jobAttributes(array $attributes): array
    {
        return array_filter([
            'status' => $attributes['job_status'] ?? null,
            'task_id' => $attributes['task_id'] ?? $attributes['taskId'] ?? null,
            'parent_job_id' => $attributes['parent_job_id'] ?? $attributes['parentJobId'] ?? null,
            'job_type' => $attributes['job_type'] ?? $attributes['jobType'] ?? null,
            'current_stage' => $attributes['current_stage'] ?? null,
            'dataset_path' => $attributes['dataset_path'] ?? null,
            'source_url' => $attributes['source_url'] ?? null,
            'local_path' => $attributes['local_path'] ?? $attributes['localPath'] ?? null,
            'content_hash' => $attributes['content_hash'] ?? $attributes['contentHash'] ?? null,
            'label' => $attributes['label'] ?? null,
            'metadata' => $attributes['job_metadata'] ?? null,
            'started_at' => $attributes['job_started_at'] ?? $attributes['started_at'] ?? null,
            'completed_at' => $attributes['job_completed_at'] ?? null,
            'finished_at' => $attributes['job_finished_at'] ?? $attributes['finished_at'] ?? null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function stageAttributes(array $attributes): array
    {
        $normalized = [
            'status' => $attributes['status'] ?? null,
            'counts' => $attributes['counts'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
            'errors' => $attributes['errors'] ?? null,
            'warnings' => $attributes['warnings'] ?? null,
            'retry_count' => $attributes['retry_count'] ?? null,
            'max_retries' => $attributes['max_retries'] ?? null,
            'started_at' => $attributes['started_at'] ?? null,
            'completed_at' => $attributes['completed_at'] ?? null,
            'failed_at' => $attributes['failed_at'] ?? null,
        ];

        return array_filter(
            $normalized,
            static fn (mixed $value, string $key): bool => $value !== null
                || (in_array($key, ['started_at', 'completed_at', 'failed_at'], true)
                    && array_key_exists($key, $attributes)),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return list<string>
     */
    public function startedStatuses(): array
    {
        return [
            PipelineJob::STATUS_RUNNING,
            'processing',
            'received',
        ];
    }

    /**
     * @return list<string>
     */
    public function nonClaimableStatuses(): array
    {
        return [
            PipelineJob::STATUS_RUNNING,
            PipelineJob::STATUS_COMPLETED,
            PipelineJob::STATUS_FAILED,
            PipelineJob::STATUS_SKIPPED,
            PipelineJob::STATUS_PARTIAL,
            'processing',
            'received',
            'cancel_requested',
        ];
    }
}
