<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Status;

use App\Services\Pipeline\State\PipelineStateService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineIngestStageSynchronizer
{
    public function __construct(private PipelineStateService $pipelineState)
    {
    }

    /**
     * @param array<string, mixed> $stage
     */
    public function sync(string $jobId, ?string $datasetPath, array $stage): void
    {
        $payload = [
            'dataset_path' => $datasetPath,
            'counts' => $stage['counts'] ?? [],
            'errors' => $stage['errors'] ?? [],
            'retry_count' => (int) ($stage['retry']['retry_count'] ?? $stage['retry']['retryCount'] ?? 0),
            'max_retries' => (int) ($stage['retry']['max_retries'] ?? $stage['retry']['maxRetries'] ?? 0),
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
}
