<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Status;

use App\Services\Pipeline\State\PipelineStateService;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineStatusService
{
    public function __construct(
        private PipelineScrapeStatusService $scrapeStatuses,
        private PipelineConversionStatusService $conversionStatuses,
        private PipelineIngestStatusService $ingestStatuses,
        private PipelineStageStatusMerger $stageMerger,
        private PipelineStateService $pipelineState,
        private ClockInterface $clock = new Clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $jobId): array
    {
        $tracked = $this->pipelineState->status($jobId);
        $scrape = $this->scrapeStatuses->stage($jobId);
        $datasetPath = $tracked['datasetPath'] ?? $scrape['datasetPath'] ?? null;
        $convert = $this->convertStage($jobId, $datasetPath);
        $ingest = $this->ingestStatuses->stage($jobId, $datasetPath);
        $tracked = $this->pipelineState->status($jobId);

        return [
            'success' => true,
            'jobId' => $jobId,
            'datasetPath' => $datasetPath,
            'currentStage' => $tracked['currentStage'] ?? $this->stageMerger->currentStage($scrape, $convert, $ingest),
            'status' => $tracked['status'] ?? $this->stageMerger->overallStatus($scrape, $convert, $ingest),
            'documentCounts' => $tracked['documentCounts'] ?? null,
            'stages' => [
                'scrape' => $this->stageMerger->mergeTrackedStage($scrape, $tracked['stages']['scrape'] ?? null),
                'convert' => $this->stageMerger->mergeTrackedStage($convert, $tracked['stages']['convert'] ?? null),
                'ingest' => $this->stageMerger->mergeTrackedStage($ingest, $tracked['stages']['ingest'] ?? null),
            ],
            'tracked' => [
                'found' => $tracked !== null,
                'startedAt' => $tracked['startedAt'] ?? null,
                'completedAt' => $tracked['completedAt'] ?? null,
                'metadata' => $tracked['metadata'] ?? [],
            ],
            'updatedAt' => $this->clock->now()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function convertStage(string $jobId, ?string $datasetPath): array
    {
        $stage = $this->conversionStatuses->stage($datasetPath);
        $this->syncConvertStage($jobId, $stage);

        return $stage;
    }

    /**
     * @param array<string, mixed> $stage
     */
    private function syncConvertStage(string $jobId, array $stage): void
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
