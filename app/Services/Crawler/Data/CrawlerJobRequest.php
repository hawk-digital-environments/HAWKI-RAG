<?php

namespace App\Services\Crawler\Data;

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
class CrawlerJobRequest
{
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
     * Create a job request from an array of parameters.
     *
     * Useful for creating requests from API inputs or configuration files.
     *
     * @param array $params Array of parameters
     * @return static
     */
    public static function fromArray(array $params): static
    {
        return new static(
            url: $params['url'] ?? '',
            label: $params['label'] ?? 'default',
            maxPages: $params['maxPages'] ?? $params['max_pages'] ?? 100,
            outputDir: $params['outputDir'] ?? $params['output_dir'] ?? '',
            skipImages: $params['skipImages'] ?? $params['skip_images'] ?? false,
            imageExceptions: $params['imageExceptions'] ?? $params['image_exceptions'] ?? null,
            dateSelector: $params['dateSelector'] ?? $params['date_selector'] ?? null,
            maxConcurrency: $params['maxConcurrency'] ?? $params['max_concurrency'] ?? 4,
            maxRpm: $params['maxRpm'] ?? $params['max_rpm'] ?? 60,
            requestDelay: $params['requestDelay'] ?? $params['request_delay'] ?? null,
            jobId: $params['jobId'] ?? $params['job_id'] ?? null,
        );
    }

    /**
     * Get the job ID, generating one if not set.
     *
     * @return string
     */
    public function getJobId(): string
    {
        return $this->jobId ?? uniqid('crawler_', true);
    }

    /**
     * Convert to array representation.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'label' => $this->label,
            'maxPages' => $this->maxPages,
            'outputDir' => $this->outputDir,
            'skipImages' => $this->skipImages,
            'imageExceptions' => $this->imageExceptions,
            'dateSelector' => $this->dateSelector,
            'maxConcurrency' => $this->maxConcurrency,
            'maxRpm' => $this->maxRpm,
            'requestDelay' => $this->requestDelay,
            'jobId' => $this->getJobId(),
        ];
    }
}
