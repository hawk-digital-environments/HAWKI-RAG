<?php

declare(strict_types=1);

namespace App\Services\Pipeline\State;

use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineStageStatusPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function forJob(PipelineJob $job): array
    {
        return [
            'jobId' => $job->job_id,
            'datasetPath' => $job->dataset_path,
            'currentStage' => $job->current_stage,
            'status' => $job->status,
            'documentCounts' => [
                'total' => $job->total_documents,
                'processed' => $job->processed_documents,
                'failed' => $job->failed_documents,
                'skipped' => $job->skipped_documents,
            ],
            'startedAt' => $this->dateValue($job->started_at),
            'completedAt' => $this->dateValue($job->completed_at),
            'metadata' => $job->metadata ?? [],
            'stages' => $job->stages
                ->mapWithKeys(fn (PipelineStageState $stage) => [
                    $stage->stage => $this->stage($stage),
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function stage(PipelineStageState $stage): array
    {
        return [
            'status' => $stage->status,
            'startedAt' => $this->dateValue($stage->started_at),
            'completedAt' => $this->dateValue($stage->completed_at),
            'failedAt' => $this->dateValue($stage->failed_at),
            'counts' => $stage->counts ?? [],
            'errors' => $stage->errors ?? [],
            'warnings' => $stage->warnings ?? [],
            'retry' => [
                'retryCount' => $stage->retry_count,
                'maxRetries' => $stage->max_retries,
            ],
            'metadata' => $stage->metadata ?? [],
            'updatedAt' => $this->dateValue($stage->updated_at),
        ];
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $value ? (string) $value : null;
    }
}
