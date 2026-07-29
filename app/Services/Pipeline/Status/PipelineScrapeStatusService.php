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
            'dataset_path' => $datasetPath,
            'started_at' => $this->values->date($liveData['started_at'] ?? null) ?? $this->values->date($stats?->started_at),
            'completed_at' => $this->values->date($liveData['completed_at'] ?? null) ?? $this->values->date($stats?->completed_at),
            'counts' => [
                'pages_crawled' => (int) ($liveData['pages_crawled'] ?? $stats?->completed_urls ?? 0),
                'total_pages' => (int) ($liveData['total_pages'] ?? $stats?->total_urls ?? $stats?->target_urls ?? 0),
                'completed_urls' => (int) ($stats?->completed_urls ?? 0),
                'failed_urls' => (int) ($stats?->failed_urls ?? 0),
                'elements' => ($process && $this->statuses->hasScrapedElementsTable()) ? $process->elements()->count() : 0,
                'files_downloaded' => (int) ($stats?->pdfs_downloaded ?? 0),
                'images_downloaded' => (int) ($stats?->images_downloaded ?? 0),
            ],
            'errors' => $this->values->array($stats?->errors),
            'warnings' => $this->values->array($stats?->warnings),
            'source' => [
                'laravel_found' => $process !== null,
                'laravel_table_available' => $this->statuses->hasScrapeJobsTable(),
                'statistics_table_available' => $this->statuses->hasScrapeStatisticsTable(),
                'elements_table_available' => $this->statuses->hasScrapedElementsTable(),
                'crawler_found' => (bool) ($live['success'] ?? false),
                'crawler_status' => $live['status'] ?? null,
            ],
        ];

        $this->stages->sync($jobId, $stage);

        return $stage;
    }
}
