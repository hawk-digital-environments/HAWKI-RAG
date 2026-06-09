<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\PipelineJob;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;

#[Singleton]
readonly class PipelineJobRecoveryRepository
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function markQueuedForRetry(PipelineJob $job, array $metadata): PipelineJob
    {
        $job->forceFill([
            'status' => PipelineJob::STATUS_QUEUED,
            'error_message' => null,
            'finished_at' => null,
            'metadata' => $metadata,
        ])->save();

        return $job->refresh();
    }

    public function lockForRecovery(PipelineJob $job): ?PipelineJob
    {
        return PipelineJob::query()
            ->whereKey($job->getKey())
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function markRecoveryQueued(PipelineJob $job, array $metadata): PipelineJob
    {
        $job->forceFill([
            'status' => PipelineJob::STATUS_QUEUED,
            'error_message' => null,
            'finished_at' => null,
            'completed_at' => null,
            'metadata' => $metadata,
        ])->save();

        return $job->refresh();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function markRecoveryPublishFailed(
        PipelineJob $job,
        string $message,
        Carbon $failedAt,
        array $metadata,
    ): PipelineJob {
        $job->forceFill([
            'status' => PipelineJob::STATUS_FAILED,
            'error_message' => $message,
            'finished_at' => $failedAt,
            'metadata' => $metadata,
        ])->save();

        return $job->refresh();
    }
}
