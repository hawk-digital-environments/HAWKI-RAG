<?php

declare(strict_types=1);

namespace App\Services\Scrape\Data;

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
 * @property-read string|null $imageExceptions Comma-separated CSS selectors for images to exclude from scraping
 * @property-read string|null $dateSelector CSS selector for extracting publication dates
 * @property-read int $maxConcurrency Maximum number of parallel requests
 * @property-read int $maxRpm Maximum requests per minute
 * @property-read int|null $requestDelay Delay between requests in milliseconds
 * @property-read string|null $jobId Unique identifier for this job (auto-generated if not provided)
 */
class ScrapeJobRequest
{
    public function __construct(
        public readonly string $url,
        public readonly string $label,
        public readonly int $maxPages = 100,
        public readonly string $outputDir = '',
        public readonly bool $skipImages = false,
        public readonly ?string $imageExceptions = null,
        public readonly ?string $dateSelector = null,
        public readonly int $maxConcurrency = 4,
        public readonly int $maxRpm = 60,
        public readonly ?int $requestDelay = null,
        public readonly bool $discoveryMode = false,
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
            imageExceptions: self::normalizeImageExceptions($params['imageExceptions'] ?? $params['image_exceptions'] ?? null),
            dateSelector: $params['dateSelector'] ?? $params['date_selector'] ?? null,
            maxConcurrency: $params['maxConcurrency'] ?? $params['max_concurrency'] ?? 4,
            maxRpm: $params['maxRpm'] ?? $params['max_rpm'] ?? 60,
            requestDelay: $params['requestDelay'] ?? $params['request_delay'] ?? null,
            discoveryMode: $params['discoveryMode'] ?? false,
            jobId: $params['jobId'] ?? $params['job_id'] ?? null,
        );
    }

    /**
     * Convert to array representation.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'job_id' => $this->jobId,
            'url' => $this->url,
            'label' => $this->label,
            'max_pages' => $this->maxPages,
            'output_dir' => $this->outputDir,
            'skip_images' => $this->skipImages,
            'image_exceptions' => $this->imageExceptions,
            'date_selector' => $this->dateSelector,
            'max_concurrency' => $this->maxConcurrency,
            'max_rpm' => $this->maxRpm,
            'request_delay' => $this->requestDelay,
            'discovery_mode' => $this->discoveryMode,
        ];
    }

    private static function normalizeImageExceptions(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
            return $value === '' ? null : $value;
        }

        if (is_array($value)) {
            $selectors = array_values(array_filter(
                array_map(static fn ($item) => is_scalar($item) ? trim((string) $item) : '', $value),
                static fn ($item) => $item !== ''
            ));

            return $selectors === [] ? null : implode(',', $selectors);
        }

        throw new \InvalidArgumentException('Image exceptions must be a string or an array of CSS selectors.');
    }
}
