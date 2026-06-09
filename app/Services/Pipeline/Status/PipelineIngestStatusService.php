<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Status;

use App\Models\JobProcessingState;
use App\Services\Pipeline\Repositories\PipelineStatusRepository;
use App\Services\Pipeline\State\PipelineStateService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineIngestStatusService
{
    public function __construct(
        private PipelineStatusRepository $statuses,
        private PipelineStateService $pipelineState,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function stage(string $jobId, ?string $datasetPath): array
    {
        if (! $this->statuses->hasIngestStateTable()) {
            return $this->emptyStage('unknown', 'Ingest state table is not available.');
        }

        $states = $this->statuses->ingestStatesForJobOrDataset($jobId, $datasetPath);
        if ($states->isEmpty()) {
            return $this->emptyStage('unknown', 'No ingest state found yet.');
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
                'updatedAt' => $this->dateValue($state->updated_at),
            ])
            ->values()
            ->all();

        $stage = [
            'status' => $this->ingestStatus($counts),
            'startedAt' => $this->dateValue($states->min('processing_started_at') ?? $states->min('first_received_at')),
            'completedAt' => $this->dateValue($states->max('completed_at')),
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
                'updatedAt' => $this->dateValue($latest->updated_at),
            ] : null,
        ];

        $this->syncStage($jobId, $datasetPath, $stage);

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

    /**
     * @param array<string, mixed> $stage
     */
    private function syncStage(string $jobId, ?string $datasetPath, array $stage): void
    {
        $payload = [
            'dataset_path' => $datasetPath,
            'counts' => $stage['counts'] ?? [],
            'errors' => $stage['errors'] ?? [],
            'retry_count' => (int) ($stage['retry']['retryCount'] ?? 0),
            'max_retries' => (int) ($stage['retry']['maxRetries'] ?? 0),
            'metadata' => [
                'latest' => $stage['latest'] ?? null,
                'source' => 'pipeline-status-reconcile',
            ],
        ];

        match ((string) ($stage['status'] ?? 'unknown')) {
            'completed' => $this->pipelineState->completeStage($jobId, PipelineStateService::STAGE_INGEST, $payload),
            'failed' => $this->pipelineState->failStage($jobId, PipelineStateService::STAGE_INGEST, $payload),
            'partial' => $this->pipelineState->partialStage($jobId, PipelineStateService::STAGE_INGEST, $payload),
            'processing', 'received' => $this->pipelineState->updateStage($jobId, PipelineStateService::STAGE_INGEST, array_merge($payload, [
                'status' => (string) $stage['status'],
            ])),
            default => null,
        };
    }

    private function emptyStage(string $status, string $message, array $extra = []): array
    {
        return array_merge([
            'status' => $status,
            'message' => $message,
            'startedAt' => null,
            'completedAt' => null,
            'counts' => [],
            'errors' => [],
            'retry' => [
                'retryCount' => null,
                'maxRetries' => null,
            ],
        ], $extra);
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return (string) $value;
    }
}
