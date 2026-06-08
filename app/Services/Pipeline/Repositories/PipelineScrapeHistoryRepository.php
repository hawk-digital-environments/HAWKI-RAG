<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\PipelineJob;
use App\Models\ScrapedElement;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineScrapeHistoryRepository
{
    public function hasCompletedScrape(string $url, string $contentHash): bool
    {
        return $this->hasScrapedElement($url, $contentHash)
            || $this->hasCompletedOrSkippedJob($url);
    }

    public function hasCompletedScraperOutput(string $url, string $contentHash): bool
    {
        return $this->hasScrapedElement($url, $contentHash)
            || $this->hasCompletedOrSkippedScrapeJob($url);
    }

    public function hasScrapedElement(string $url, string $contentHash): bool
    {
        return ScrapedElement::query()
            ->where('page_url_hash', $contentHash)
            ->orWhere('page_url', $url)
            ->exists();
    }

    public function hasCompletedOrSkippedJob(string $url): bool
    {
        return PipelineJob::query()
            ->where('source_url', $url)
            ->whereIn('status', [PipelineJob::STATUS_COMPLETED, PipelineJob::STATUS_SKIPPED])
            ->exists();
    }

    public function hasCompletedOrSkippedScrapeJob(string $url): bool
    {
        return PipelineJob::query()
            ->where('source_url', $url)
            ->where('job_type', PipelineJob::TYPE_SCRAPE)
            ->whereIn('status', [PipelineJob::STATUS_COMPLETED, PipelineJob::STATUS_SKIPPED])
            ->exists();
    }
}
