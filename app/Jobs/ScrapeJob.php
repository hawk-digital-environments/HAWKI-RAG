<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScrapeJob implements ShouldQueue
{
    use Queueable;

    /**
     * Input data transfer object for initiating a crawler job.
     *
     * Thisimmutable object encapsulates all input parameters required to start
     * a crawler job. It serves as the entry point for the crawler pipeline and
     * can be created from console commands, API requests, or queue jobs.
     *
     * @property-read string $url Target URL or file path to crawl
     * @property-read string $label Label/prefix for organizing crawl directories
     * @property-read int $maxPages Maximum number of pages to crawl (0 = unlimited)
     * @property-read string $outputDir Base directory for storing crawled data
     * @property-read bool $skipImages Whether to skip downloading images
     * @property-read array|null $imageExceptions CSS selectors for images to exclude from scraping
     * @property-read string|null $dateSelector CSS selector for extracting publication dates
     * @property-read int $maxConcurrency Maximum number of parallel requests
     * @property-read int $maxRpm Maximum requests per minute
     * @property-read int|null $requestDelay Delay between requests in milliseconds
     * @property-read string|null $jobId Unique identifier for this job (auto-generated if not provided)
     */


    public function __construct(
        public readonly string $url,
        public readonly string $label,
        public readonly int $maxPages = 100,
        public readonly string $outputDir = '',
        public readonly bool $skipImages = false,
        public readonly ?array $imageExceptions = null,
        public readonly ?string $dateSelector = null,
        public readonly int $maxConcurrency = 4,
        public readonly int $maxRpm = 60,
        public readonly ?int $requestDelay = null,
        public readonly ?string $jobId = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        //
    }
}
