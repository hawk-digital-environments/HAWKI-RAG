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
        private PipelineStateService $pipelineState,
        private PipelineStageStatusMerger $stageMerger,
        private PipelineConversionStageSynchronizer $conversionStages,
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
        $this->conversionStages->sync($jobId, $stage);

        return $stage;
    }
}
