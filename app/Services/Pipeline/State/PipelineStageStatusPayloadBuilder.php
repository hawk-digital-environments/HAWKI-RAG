<?php

declare(strict_types=1);

namespace App\Services\Pipeline\State;

use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Services\Pipeline\PipelineManagedDocumentViewService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineStageStatusPayloadBuilder
{
    public function __construct(
        private PipelineManagedDocumentViewService $documents,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function forJob(PipelineJob $job): array
    {
        $managedDocuments = $this->documents->managedDocumentsForJob($job);

        return [
            'job_id' => $job->job_id,
            'dataset_path' => $job->dataset_path,
            'current_stage' => $job->current_stage,
            'status' => $job->status,
            'document_counts' => [
                'total' => $job->total_documents,
                'processed' => $job->processed_documents,
                'failed' => $job->failed_documents,
                'skipped' => $job->skipped_documents,
            ],
            'started_at' => $this->dateValue($job->started_at),
            'completed_at' => $this->dateValue($job->completed_at),
            'metadata' => $job->metadata ?? [],
            'managed_documents' => $managedDocuments,
            'managed_document_count' => count($managedDocuments),
            'source' => $this->documents->source(
                is_string($job->source_id) ? $job->source_id : null,
                $job->source_url,
                null,
                $job->task_id,
            ),
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
            'started_at' => $this->dateValue($stage->started_at),
            'completed_at' => $this->dateValue($stage->completed_at),
            'failed_at' => $this->dateValue($stage->failed_at),
            'counts' => $stage->counts ?? [],
            'errors' => $stage->errors ?? [],
            'warnings' => $stage->warnings ?? [],
            'retry' => [
                'retry_count' => $stage->retry_count,
                'max_retries' => $stage->max_retries,
            ],
            'metadata' => $stage->metadata ?? [],
            'updated_at' => $this->dateValue($stage->updated_at),
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
