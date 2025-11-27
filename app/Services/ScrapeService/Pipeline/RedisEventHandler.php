<?php

namespace App\Services\ScrapeService\Pipeline;

use App\Models\ScrapeProcess;
use App\Services\ScrapeService\Data\ScrapeEventPacket;
use Illuminate\Support\Facades\Log;

class RedisEventHandler
{


    /**
     * Handle event-specific logic.
     *
     * @param ScrapeProcess $process
     * @param ScrapeEventPacket $packet
     * @return void
     */
    public function handleEventType(ScrapeProcess $process, ScrapeEventPacket $packet): void
    {
        match ($packet->event) {
            'sitemap_detected' => $this->handleSitemapDetected($process, $packet),
            'urls_discovered' => $this->handleUrlsDiscovered($process, $packet),
            'crawling_started' => $this->handleCrawlingStarted($process, $packet),
            'url_fetching' => $this->handleUrlFetching($process, $packet),
            'url_scraped' => $this->handleUrlScraped($process, $packet),
            'url_completed' => $this->handleUrlCompleted($process, $packet),
            'job_completed' => $this->handleJobCompleted($process, $packet),
            default => Log::debug("No specific handler for event: {$packet->event}")
        };
    }


    // Event-specific handlers

    protected function handleSitemapDetected(ScrapeProcess $process, ScrapeEventPacket $packet): void
    {
        Log::info("Sitemap detected", [
            'job_id' => $packet->jobId,
            'sitemap_url' => $packet->data['sitemap_url'] ?? null,
            'total_urls' => $packet->data['total_urls'] ?? 0
        ]);
    }

    protected function handleUrlsDiscovered(ScrapeProcess $process, ScrapeEventPacket $packet): void
    {
        Log::info("URLs discovered", [
            'job_id' => $packet->jobId,
            'urls_count' => $packet->data['urls_count'] ?? 0,
            'total_to_crawl' => $packet->data['total_to_crawl'] ?? 0
        ]);
    }

    protected function handleCrawlingStarted(ScrapeProcess $process, ScrapeEventPacket $packet): void
    {
        Log::info("Crawling started", [
            'job_id' => $packet->jobId,
            'total_pages' => $packet->data['total_pages'] ?? 0
        ]);
    }

    protected function handleUrlFetching(ScrapeProcess $process, ScrapeEventPacket $packet): void
    {
        // You might want to throttle these logs or store them differently
        Log::debug("Fetching URL", [
            'job_id' => $packet->jobId,
            'url' => $packet->data['url'] ?? null,
            'page_number' => $packet->data['page_number'] ?? null
        ]);
    }

    protected function handleUrlScraped(ScrapeProcess $process, ScrapeEventPacket $packet): void
    {
        $success = $packet->data['success'] ?? false;
        $level = $success ? 'info' : 'warning';

        Log::log($level, "URL scraped", [
            'job_id' => $packet->jobId,
            'url' => $packet->data['url'] ?? null,
            'success' => $success,
            'error' => $packet->data['error'] ?? null
        ]);
    }

    protected function handleUrlCompleted(ScrapeProcess $process, ScrapeEventPacket $packet): void
    {
        Log::info("URL processing completed", [
            'job_id' => $packet->jobId,
            'url' => $packet->data['url'] ?? null,
            'files_created' => $packet->data['files_created'] ?? []
        ]);
    }

    protected function handleJobCompleted(ScrapeProcess $process, ScrapeEventPacket $packet): void
    {
        $success = $packet->data['success'] ?? false;
        $level = $success ? 'info' : 'error';

        Log::log($level, "Job completed", [
            'job_id' => $packet->jobId,
            'pages_crawled' => $packet->data['pages_crawled'] ?? 0,
            'output_directory' => $packet->data['output_directory'] ?? null,
            'success' => $success,
            'error' => $packet->data['error'] ?? null
        ]);
    }

}
