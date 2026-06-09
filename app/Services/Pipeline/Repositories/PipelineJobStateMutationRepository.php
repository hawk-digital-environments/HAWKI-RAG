<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

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
            'error_message' => $message,
            'finished_at' => $failedAt,
        ])->save();

        return $job->refresh();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function markScrapeMonitorCompleted(
        PipelineJob $job,
        string $datasetPath,
        Carbon $completedAt,
        array $metadata,
    ): PipelineJob {
        $job->forceFill([
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'status' => PipelineJob::STATUS_COMPLETED,
            'local_path' => $datasetPath,
            'completed_at' => $completedAt,
            'finished_at' => $completedAt,
            'metadata' => $metadata,
        ])->save();

        return $job->refresh()->loadMissing('task');
    }
}
