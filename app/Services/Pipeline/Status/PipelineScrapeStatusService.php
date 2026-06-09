<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Status;

use App\Services\Pipeline\Repositories\PipelineStatusRepository;
use App\Services\Pipeline\State\PipelineStateService;
use App\Services\Scrape\ScrapeService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineScrapeStatusService
{
    public function __construct(
        private ScrapeService $scrapeService,
        private PipelineStatusRepository $statuses,
        private PipelineStateService $pipelineState,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function stage(string $jobId): array
    {
        $process = $this->statuses->findScrapeProcess($jobId);

        $live = $this->scrapeService->getCrawlerStatus($jobId);
        $liveData = ($live['success'] ?? false) && is_array($live['data'] ?? null)
            ? $live['data']
            : [];

        $request = is_array($process?->request) ? $process->request : [];
        $stats = ($process && $this->statuses->hasScrapeStatisticsTable()) ? $process->stats : null;
        $datasetPath = $liveData['output_directory']
            ?? $request['output_dir']
            ?? $request['outputDir']
            ?? null;

        $stage = [
            'status' => $liveData['status'] ?? $process?->stage ?? 'unknown',
            'message' => $liveData['message'] ?? null,
            'datasetPath' => $datasetPath,
            'startedAt' => $this->dateValue($liveData['started_at'] ?? null) ?? $this->dateValue($stats?->started_at),
            'completedAt' => $this->dateValue($liveData['completed_at'] ?? null) ?? $this->dateValue($stats?->completed_at),
            'counts' => [
                'pagesCrawled' => (int) ($liveData['pages_crawled'] ?? $stats?->completed_urls ?? 0),
                'totalPages' => (int) ($liveData['total_pages'] ?? $stats?->total_urls ?? $stats?->target_urls ?? 0),
                'completedUrls' => (int) ($stats?->completed_urls ?? 0),
                'failedUrls' => (int) ($stats?->failed_urls ?? 0),
                'elements' => ($process && $this->statuses->hasScrapedElementsTable()) ? $process->elements()->count() : 0,
                'filesDownloaded' => (int) ($stats?->pdfs_downloaded ?? 0),
                'imagesDownloaded' => (int) ($stats?->images_downloaded ?? 0),
            ],
            'errors' => $this->arrayValue($stats?->errors),
            'warnings' => $this->arrayValue($stats?->warnings),
            'source' => [
                'laravelFound' => $process !== null,
                'laravelTableAvailable' => $this->statuses->hasScrapeJobsTable(),
                'statisticsTableAvailable' => $this->statuses->hasScrapeStatisticsTable(),
                'elementsTableAvailable' => $this->statuses->hasScrapedElementsTable(),
                'crawlerFound' => (bool) ($live['success'] ?? false),
                'crawlerStatus' => $live['status'] ?? null,
            ],
        ];

        $this->syncStage($jobId, $stage);

        return $stage;
    }

    /**
     * @param array<string, mixed> $stage
     */
    private function syncStage(string $jobId, array $stage): void
    {
        $status = (string) ($stage['status'] ?? 'unknown');
        $payload = [
            'dataset_path' => $stage['datasetPath'] ?? null,
            'counts' => [
                'totalPages' => (int) ($stage['counts']['totalPages'] ?? 0),
                'pagesCrawled' => (int) ($stage['counts']['pagesCrawled'] ?? 0),
                'failedUrls' => (int) ($stage['counts']['failedUrls'] ?? 0),
            ],
            'errors' => $stage['errors'] ?? [],
            'warnings' => $stage['warnings'] ?? [],
            'metadata' => [
                'message' => $stage['message'] ?? null,
                'source' => $stage['source'] ?? [],
            ],
        ];

        if ($status === 'completed') {
            $this->pipelineState->completeStage($jobId, PipelineStateService::STAGE_SCRAPE, $payload);

            return;
        }

        if ($status === 'failed') {
            $this->pipelineState->failStage($jobId, PipelineStateService::STAGE_SCRAPE, $payload);

            return;
        }

        if (! in_array($status, ['unknown', 'pending'], true)) {
            $this->pipelineState->updateStage($jobId, PipelineStateService::STAGE_SCRAPE, array_merge($payload, [
                'status' => in_array($status, ['running', 'processing', 'received'], true) ? $status : 'running',
            ]));
        }
    }

    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
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
