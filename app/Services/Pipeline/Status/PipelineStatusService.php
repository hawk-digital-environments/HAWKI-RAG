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
        private PipelineStateService $pipelineState,
        private PipelineStageStatusMerger $stageMerger,
        private PipelineConversionStageSynchronizer $conversionStages,
        private PipelineStageEmptyResponseFactory $emptyStages,
        private ClockInterface $clock = new Clock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function show(string $jobId): array
    {
        $tracked = $this->pipelineState->status($jobId);
        $scrape = $this->scrapeStatuses->stage($jobId);
        $datasetPath = $tracked['dataset_path'] ?? $tracked['datasetPath'] ?? $scrape['dataset_path'] ?? $scrape['datasetPath'] ?? null;
        $convert = $this->convertStage($jobId, $datasetPath);

        // Reconciliation can persist conversion and ingest stages during this request.
        $tracked = $this->pipelineState->status($jobId);
        $ingest = $this->persistedIngestStage($tracked);

        return [
            'success' => true,
            'job_id' => $jobId,
            'dataset_path' => $datasetPath,
            'current_stage' => $tracked['current_stage'] ?? $tracked['currentStage'] ?? $this->stageMerger->currentStage($scrape, $convert, $ingest),
            'status' => $tracked['status'] ?? $this->stageMerger->overallStatus($scrape, $convert, $ingest),
            'document_counts' => $tracked['document_counts'] ?? $tracked['documentCounts'] ?? null,
            'managed_documents' => is_array($tracked['managed_documents'] ?? null) ? $tracked['managed_documents'] : [],
            'managed_document_count' => (int) ($tracked['managed_document_count'] ?? 0),
            'source' => is_array($tracked['source'] ?? null) ? $tracked['source'] : null,
            'stages' => [
                'scrape' => $this->stageMerger->mergeTrackedStage($scrape, $tracked['stages']['scrape'] ?? null),
                'convert' => $this->stageMerger->mergeTrackedStage($convert, $tracked['stages']['convert'] ?? null),
                'ingest' => $ingest,
            ],
            'tracked' => [
                'found' => $tracked !== null,
                'started_at' => $tracked['started_at'] ?? $tracked['startedAt'] ?? null,
                'completed_at' => $tracked['completed_at'] ?? $tracked['completedAt'] ?? null,
                'metadata' => $tracked['metadata'] ?? [],
                'managed_documents' => is_array($tracked['managed_documents'] ?? null) ? $tracked['managed_documents'] : [],
                'managed_document_count' => (int) ($tracked['managed_document_count'] ?? 0),
                'source' => is_array($tracked['source'] ?? null) ? $tracked['source'] : null,
            ],
            'updated_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function convertStage(string $jobId, ?string $datasetPath): array
    {
        $stage = $this->conversionStatuses->stage($datasetPath);
        $this->conversionStages->sync($jobId, $stage);

        return $stage;
    }

    /**
     * @param  array<string, mixed>|null  $tracked
     * @return array<string, mixed>
     */
    private function persistedIngestStage(?array $tracked): array
    {
        $stage = $tracked['stages'][PipelineStateService::STAGE_INGEST] ?? null;
        if (is_array($stage)) {
            return $stage;
        }

        return $this->emptyStages->stage(
            'not_tracked',
            'No persisted ingest stage has been recorded for this job.',
        );
    }
}
