<?php

namespace App\Jobs;

use App\Services\Pipeline\PipelineLogger;
use App\Services\Pipeline\PipelineStateService;
use App\Services\ScrapeService\ScrapeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class MonitorScrapeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 240;
    public int $timeout = 120;

    public function __construct(
        public readonly string $jobId,
    ) {
        $this->onQueue('default');
    }

    public function handle(ScrapeService $scrapeService, PipelineStateService $pipelineState): void
    {
        if ($pipelineState->isStageCompleted($this->jobId, PipelineStateService::STAGE_CONVERT)) {
            return;
        }

        $result = $scrapeService->getCrawlerStatus($this->jobId);
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $crawlerStatus = strtolower((string) ($data['status'] ?? ''));
        $datasetPath = (string) ($data['output_directory'] ?? '');

        if (!($result['success'] ?? false)) {
            if ($this->attempts() < 5) {
                $this->release(10);
                return;
            }

            $pipelineState->failStage($this->jobId, PipelineStateService::STAGE_SCRAPE, [
                'errors' => [[
                    'message' => $result['message'] ?? 'Unable to read Crawl4AI status.',
                    'status' => $result['status'] ?? null,
                    'updatedAt' => now()->toIso8601String(),
                ]],
                'metadata' => ['source' => 'MonitorScrapeJob'],
            ]);
            return;
        }

        $counts = [
            'totalPages' => (int) ($data['total_pages'] ?? 0),
            'pagesCrawled' => (int) ($data['pages_crawled'] ?? 0),
            'failedUrls' => (int) ($data['failed_urls'] ?? 0),
        ];

        if ($crawlerStatus === 'completed') {
            $pipelineState->completeStage($this->jobId, PipelineStateService::STAGE_SCRAPE, [
                'dataset_path' => $datasetPath !== '' ? $datasetPath : null,
                'counts' => $counts,
                'metadata' => [
                    'message' => $data['message'] ?? 'Crawl completed.',
                    'source' => 'MonitorScrapeJob',
                ],
            ]);

            PipelineLogger::success('pipeline', [
                'job_id' => $this->jobId,
                'pipeline_stage' => 'scrape_to_convert_trigger',
                'output_dir' => $datasetPath,
            ]);

            if ($datasetPath !== '') {
                ConvertPipelineDatasetJob::dispatch($this->jobId, $datasetPath);
            }
            return;
        }

        if (in_array($crawlerStatus, ['failed', 'cancelled', 'canceled', 'stopped'], true)) {
            $pipelineState->failStage($this->jobId, PipelineStateService::STAGE_SCRAPE, [
                'dataset_path' => $datasetPath !== '' ? $datasetPath : null,
                'counts' => $counts,
                'errors' => [[
                    'message' => $data['message'] ?? "Crawl ended with status {$crawlerStatus}.",
                    'updatedAt' => now()->toIso8601String(),
                ]],
                'metadata' => ['source' => 'MonitorScrapeJob'],
            ]);
            return;
        }

        $pipelineState->updateStage($this->jobId, PipelineStateService::STAGE_SCRAPE, [
            'status' => 'running',
            'dataset_path' => $datasetPath !== '' ? $datasetPath : null,
            'counts' => $counts,
            'metadata' => [
                'crawlerStatus' => $crawlerStatus ?: 'unknown',
                'message' => $data['message'] ?? null,
                'source' => 'MonitorScrapeJob',
            ],
        ]);

        $this->release(10);
    }

    public function failed(Throwable $exception): void
    {
        app(PipelineStateService::class)->failStage($this->jobId, PipelineStateService::STAGE_SCRAPE, [
            'errors' => [[
                'message' => $exception->getMessage(),
                'type' => class_basename($exception),
                'updatedAt' => now()->toIso8601String(),
            ]],
            'metadata' => ['source' => 'MonitorScrapeJob'],
        ]);
    }
}
