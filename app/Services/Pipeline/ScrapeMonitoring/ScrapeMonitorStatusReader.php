<?php

declare(strict_types=1);

namespace App\Services\Pipeline\ScrapeMonitoring;

use App\Services\Scrape\ScrapeService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeMonitorStatusReader
{
    public function __construct(
        private ScrapeService $scrapeService,
    ) {
    }

    public function read(array $event): ScrapeMonitorStatusSnapshot
    {
        $result = $this->scrapeService->getCrawlerStatus((string) $event['job_id']);
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        return new ScrapeMonitorStatusSnapshot(
            success: (bool) ($result['success'] ?? false),
            result: $result,
            data: $data,
            crawlerStatus: strtolower((string) ($data['status'] ?? '')),
            datasetPath: (string) ($data['output_directory'] ?? ''),
            counts: [
                'totalPages' => (int) ($data['total_pages'] ?? 0),
                'pagesCrawled' => (int) ($data['pages_crawled'] ?? 0),
                'failedUrls' => (int) ($data['failed_urls'] ?? 0),
            ],
            message: (string) ($result['message'] ?? 'Unable to read Crawl4AI status.'),
            httpStatus: $result['status'] ?? null,
        );
    }
}
