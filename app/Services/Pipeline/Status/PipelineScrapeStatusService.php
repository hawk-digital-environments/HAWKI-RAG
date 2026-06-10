<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Status;

use App\Services\Pipeline\Repositories\PipelineStatusRepository;
use App\Services\Scrape\ScrapeService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineScrapeStatusService
{
    public function __construct(
        private ScrapeService $scrapeService,
        private PipelineStatusRepository $statuses,
        private PipelineScrapeStageSynchronizer $stages,
        private PipelineStageValueFormatter $values,
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
            'startedAt' => $this->values->date($liveData['started_at'] ?? null) ?? $this->values->date($stats?->started_at),
            'completedAt' => $this->values->date($liveData['completed_at'] ?? null) ?? $this->values->date($stats?->completed_at),
            'counts' => [
                'pagesCrawled' => (int) ($liveData['pages_crawled'] ?? $stats?->completed_urls ?? 0),
                'totalPages' => (int) ($liveData['total_pages'] ?? $stats?->total_urls ?? $stats?->target_urls ?? 0),
                'completedUrls' => (int) ($stats?->completed_urls ?? 0),
                'failedUrls' => (int) ($stats?->failed_urls ?? 0),
                'elements' => ($process && $this->statuses->hasScrapedElementsTable()) ? $process->elements()->count() : 0,
                'filesDownloaded' => (int) ($stats?->pdfs_downloaded ?? 0),
                'imagesDownloaded' => (int) ($stats?->images_downloaded ?? 0),
            ],
            'errors' => $this->values->array($stats?->errors),
            'warnings' => $this->values->array($stats?->warnings),
            'source' => [
                'laravelFound' => $process !== null,
                'laravelTableAvailable' => $this->statuses->hasScrapeJobsTable(),
                'statisticsTableAvailable' => $this->statuses->hasScrapeStatisticsTable(),
                'elementsTableAvailable' => $this->statuses->hasScrapedElementsTable(),
                'crawlerFound' => (bool) ($live['success'] ?? false),
                'crawlerStatus' => $live['status'] ?? null,
            ],
        ];

        $this->stages->sync($jobId, $stage);

        return $stage;
    }
}
