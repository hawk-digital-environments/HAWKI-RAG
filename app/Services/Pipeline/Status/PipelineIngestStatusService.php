<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Status;

use App\Models\JobProcessingState;
use App\Services\Pipeline\Repositories\PipelineStatusRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineIngestStatusService
{
    public function __construct(
        private PipelineStatusRepository $statuses,
        private PipelineIngestStageSynchronizer $stages,
        private PipelineStageEmptyResponseFactory $emptyStages,
        private PipelineStageValueFormatter $values,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function stage(string $jobId, ?string $datasetPath): array
    {
        if (! $this->statuses->hasIngestStateTable()) {
            return $this->emptyStages->stage('unknown', 'Ingest state table is not available.');
        }

        $states = $this->statuses->ingestStatesForJobOrDataset($jobId, $datasetPath);
        if ($states->isEmpty()) {
            return $this->emptyStages->stage('unknown', 'No ingest state found yet.');
        }

        $counts = $states->countBy('status')->all();
        $latest = $states->first();
        $errors = $states
            ->filter(fn (JobProcessingState $state) => filled($state->error_message))
            ->map(fn (JobProcessingState $state) => [
                'jobId' => $state->job_id,
                'status' => $state->status,
                'errorType' => $state->error_type,
                'message' => $state->error_message,
                'retryCount' => $state->retry_count,
                'maxRetries' => $state->max_retries,
                'updatedAt' => $this->values->date($state->updated_at),
            ])
            ->values()
            ->all();

        $stage = [
            'status' => $this->ingestStatus($counts),
            'startedAt' => $this->values->date($states->min('processing_started_at') ?? $states->min('first_received_at')),
            'completedAt' => $this->values->date($states->max('completed_at')),
            'counts' => [
                'total' => $states->count(),
                'received' => (int) ($counts[JobProcessingState::STATUS_RECEIVED] ?? 0),
                'processing' => (int) ($counts[JobProcessingState::STATUS_PROCESSING] ?? 0),
                'completed' => (int) ($counts[JobProcessingState::STATUS_COMPLETED] ?? 0),
                'failed' => (int) ($counts[JobProcessingState::STATUS_FAILED] ?? 0),
            ],
            'retry' => [
                'retryCount' => (int) ($latest?->retry_count ?? 0),
                'maxRetries' => (int) ($latest?->max_retries ?? 0),
            ],
            'errors' => $errors,
            'latest' => $latest ? [
                'jobId' => $latest->job_id,
                'status' => $latest->status,
                'inputPath' => $latest->input_path,
                'outputPath' => $latest->output_path,
                'updatedAt' => $this->values->date($latest->updated_at),
            ] : null,
        ];

        $this->stages->sync($jobId, $datasetPath, $stage);

        return $stage;
    }

    private function ingestStatus(array $counts): string
    {
        $received = (int) ($counts[JobProcessingState::STATUS_RECEIVED] ?? 0);
        $processing = (int) ($counts[JobProcessingState::STATUS_PROCESSING] ?? 0);
        $completed = (int) ($counts[JobProcessingState::STATUS_COMPLETED] ?? 0);
        $failed = (int) ($counts[JobProcessingState::STATUS_FAILED] ?? 0);

        if ($processing > 0) {
            return 'processing';
        }
        if ($received > 0) {
            return 'received';
        }
        if ($failed > 0 && $completed > 0) {
            return 'partial';
        }
        if ($failed > 0) {
            return 'failed';
        }
        if ($completed > 0) {
            return 'completed';
        }

        return 'unknown';
    }
}
