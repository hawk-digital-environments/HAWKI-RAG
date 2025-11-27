<?php

namespace App\Services\ScrapeService\Pipeline;

use App\Services\ScrapeService\Data\ScrapeContext;
use App\Services\ScrapeService\Data\ScrapeEventPacket;
use Illuminate\Support\Facades\Log;

class ScrapeEventHandler
{
    /**
     * Handle event-specific logic.
     *
     * @param ScrapeContext $context
     * @param ScrapeEventPacket $packet
     * @return void
     */
    public function handleEventType(ScrapeContext $context, ScrapeEventPacket $packet): void
    {
        match ($packet->event) {
            'sitemap_detected' => $this->handleSitemapDetected($context, $packet),
            'stage_changed' => $this->handleStageChange($context, $packet),
            'progress_update' => $this->handleProgressUpdate($context, $packet),
            'job_summary' => $this->handleJobSummary($context, $packet),
            'urls_discovered' => $this->handleUrlsDiscovered($context, $packet),
            'crawling_started' => $this->handleCrawlingStarted($context, $packet),
            'job_completed' => $this->handleFinishedJobs($context, $packet),
            default => Log::warning("Unknown Packet Received:",$packet->toArray())
        };
    }


    // Event-specific handlers
    protected function handleSitemapDetected(ScrapeContext $context, ScrapeEventPacket $packet): void
    {
        $context->addMetadata('sitemap_detected', true);
        $context->addMetadata('total_urls', $packet->data['total_urls']);
    }

    protected function handleUrlsDiscovered(ScrapeContext $context, ScrapeEventPacket $packet): void
    {
        $context->addMetadata('total_to_crawl', $packet->data['total_to_crawl']);
    }

    protected function handleCrawlingStarted(ScrapeContext $context, ScrapeEventPacket $packet): void
    {
        $context->setStage('crawling');
    }

    protected function handleStageChange(ScrapeContext $context, ScrapeEventPacket $packet): void{
        $context->setStage($packet->data['stage']);
    }

    protected function handleProgressUpdate(ScrapeContext $context, ScrapeEventPacket $packet): void{
        $context->setProgress($packet->data['progress_percentage']);
    }

    protected function handleJobSummary(ScrapeContext $context, ScrapeEventPacket $packet): void{
        $context->setStage($packet->data['stage']);
        $context->addMetadata('job_summary', $packet->data);
    }

    protected function handleFinishedJobs(ScrapeContext $context, ScrapeEventPacket $packet): void
    {
        $context->setEndProcess();
    }

}
