<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Status;

use App\Services\Pipeline\State\PipelineStateService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineConversionStageSynchronizer
{
    public function __construct(private PipelineStateService $pipelineState)
    {
    }

    /**
     * @param array<string, mixed> $stage
     */
    public function sync(string $jobId, array $stage): void
    {
        $payload = [
            'dataset_path' => $stage['datasetPath'] ?? null,
            'counts' => [
                'total' => (int) ($stage['counts']['sourceFiles'] ?? 0),
                'sourceFiles' => (int) ($stage['counts']['sourceFiles'] ?? 0),
                'processed' => (int) ($stage['counts']['convertedFiles'] ?? 0),
                'convertedFiles' => (int) ($stage['counts']['convertedFiles'] ?? 0),
                'failed' => (int) ($stage['counts']['failedFiles'] ?? 0),
                'failedFiles' => (int) ($stage['counts']['failedFiles'] ?? 0),
            ],
            'errors' => $stage['errors'] ?? [],
            'max_retries' => (int) ($stage['retry']['maxRetries'] ?? 0),
            'metadata' => [
                'supportedExtensions' => $stage['supportedExtensions'] ?? [],
                'source' => 'pipeline-status-reconcile',
            ],
        ];

        $status = (string) ($stage['status'] ?? 'unknown');

        match ($status) {
            'completed' => $this->pipelineState->completeStage($jobId, PipelineStateService::STAGE_CONVERT, $payload),
            'failed' => $this->pipelineState->failStage($jobId, PipelineStateService::STAGE_CONVERT, $payload),
            'partial' => $this->pipelineState->partialStage($jobId, PipelineStateService::STAGE_CONVERT, $payload),
            'skipped' => $this->pipelineState->skipStage($jobId, PipelineStateService::STAGE_CONVERT, $payload),
            'pending' => $this->pipelineState->updateStage($jobId, PipelineStateService::STAGE_CONVERT, array_merge($payload, ['status' => 'pending'])),
            default => null,
        };

        $resolvedDatasetPath = (string) ($stage['datasetPath'] ?? '');
        if ($status === 'skipped'
            && ! $this->pipelineState->isStageClaimedOrDone($jobId, PipelineStateService::STAGE_INGEST)) {
            $this->pipelineState->skipStage($jobId, PipelineStateService::STAGE_INGEST, [
                'dataset_path' => $resolvedDatasetPath !== '' ? $resolvedDatasetPath : ($stage['datasetPath'] ?? null),
                'counts' => [],
                'metadata' => [
                    'reason' => 'Conversion skipped because no supported source files were found.',
                    'source' => 'pipeline-status-reconcile',
                ],
            ]);
        }
    }
}
