<?php

namespace App\Services\Crawler\Pipeline;

use App\Services\Crawler\Data\CrawlerConfig;
use App\Services\Crawler\Data\DirectoryAnalysis;
use App\Services\Crawler\Data\UrlProcessingOptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CrawlerConfigurationService
{
    public function __construct(
        private CrawlerProgressService $progressService,
        private CrawlerDirectoryService $directoryService,
        private CrawlerUrlService $urlService
    ) {}

    /**
     * Process and validate the input URL, determining its type and extracting relevant information.
     *
     * Handles three types of inputs:
     * 1. Local file containing a list of URLs (one per line)
     * 2. Sitemap URL (contains 'sitemap' or '.xml' in the URL)
     * 3. Direct URL to crawl
     *
     * For local files, extracts all valid URLs and determines the base URL from the first entry.
     * For remote URLs, validates the URL format and determines if it's a sitemap or direct URL.
     *
     * @param string $url The URL or file path to process
     * @return UrlProcessingOptions Processed URL information including type, base URL, and sitemap URLs
     * @throws \InvalidArgumentException If URL is invalid or file is not readable/contains no valid URLs
     */
    public function processUrl(string $url): UrlProcessingOptions
    {
        // Check if the input is a local file path
        $isLocalFile = File::exists($url) && File::isReadable($url);

        // Validate URL format for remote URLs
        if (!$isLocalFile && !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid URL provided or file not found/readable.');
        }

        $sitemapUrls = [];
        $baseUrl = null;

        // Process local file containing list of URLs
        if ($isLocalFile) {
            // Read file, parse URLs line by line, and filter out invalid entries
            $sitemapUrls = collect(explode("\n", File::get($url)))
                ->map(fn($line) => trim($line))
                ->filter(fn($line) => filled($line))
                ->filter(fn($line) => filter_var($line, FILTER_VALIDATE_URL) !== false)
                ->values()
                ->toArray();

            if (blank($sitemapUrls)) {
                throw new \InvalidArgumentException('The sitemap file does not contain any valid URLs.');
            }

            // Extract base URL from the first URL in the list
            $parsedUrl = parse_url($sitemapUrls[0]);
            $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
        }

        // Determine source type based on input characteristics
        $sourceType = 'direct';
        if ($isLocalFile) {
            $sourceType = 'local';
        } else {
            $lowerUrl = Str::lower($url);
            if (Str::contains($lowerUrl, ['sitemap', '.xml'])) {
                $sourceType = 'sitemap';
            }
        }

        return new UrlProcessingOptions(
            url: $url,
            isLocalFile: $isLocalFile,
            baseUrl: $baseUrl,
            sourceType: $sourceType,
            sitemapUrls: $sitemapUrls
        );
    }

    /**
     * Build the crawler configuration object from various input parameters.
     *
     * Constructs a complete CrawlerConfig data object that will be used to execute the crawling process.
     * Handles continuation logic by including incomplete directories and empty directories that can be
     * reused when resuming a previous crawl session.
     *
     * @param UrlProcessingOptions $urlOptions Processed URL information from processUrl()
     * @param string $outputDir Base directory where crawled content will be stored
     * @param string $label Label/prefix for organizing crawled directories
     * @param int $maxPages Maximum number of pages to crawl (0 for unlimited)
     * @param bool $skipImages Whether to skip downloading images
     * @param int $startFromIndex Index to start crawling from (1-based)
     * @param DirectoryAnalysis $directoryAnalysis Analysis of existing directories for continuation
     * @param bool $shouldContinue Whether to continue from a previous crawl session
     * @param array|null $imageExceptions Optional array of image URLs to download even if skipImages is true
     * @param string|null $dateSelector Optional CSS selector for extracting dates from pages
     * @return CrawlerConfig Complete crawler configuration object
     */
    public function buildConfig(
        UrlProcessingOptions $urlOptions,
        string $outputDir,
        string $label,
        int $maxPages,
        bool $skipImages,
        int $startFromIndex,
        DirectoryAnalysis $directoryAnalysis,
        bool $shouldContinue,
        ?array $imageExceptions = null,
        ?string $dateSelector = null
    ): CrawlerConfig {
        // Use base URL for local files, otherwise use the original URL
        $url = $urlOptions->isLocalFile ? $urlOptions->baseUrl : $urlOptions->url;

        return new CrawlerConfig(
            url: $url,
            maxPages: $maxPages,
            outputDir: $outputDir,
            label: $label,
            skipImages: $skipImages,
            startFromIndex: $startFromIndex,
            // Include incomplete directories only if continuing a previous session
            incompleteDirectories: $shouldContinue ? $directoryAnalysis->incompleteUrls : [],
            // Calculate empty directories that can be reused (incomplete but without URL mapping)
            emptyDirectoriesToReuse: $shouldContinue
                ? array_diff($directoryAnalysis->incomplete, array_keys($directoryAnalysis->incompleteUrls))
                : [],
            sourceType: $urlOptions->sourceType,
            imageExceptions: $imageExceptions,
            dateSelector: $dateSelector
        );
    }

    /**
     * Apply URL continuation logic to the crawler configuration.
     *
     * This function handles resuming crawls from where they left off. It calculates the offset
     * based on existing directories and adjusts the URL list accordingly. For local file sources,
     * it slices the URL array. For remote sources (sitemaps/direct URLs), it adds a continueOffset
     * that will be used by the crawler to skip already-processed pages.
     *
     * @param CrawlerConfig $config Base crawler configuration to modify
     * @param UrlProcessingOptions $urlOptions URL processing options containing source type and URLs
     * @param bool $shouldContinue Whether continuation is enabled
     * @param int $startFromIndex The index to start from (1-based, used for validation)
     * @param string $outputDir Output directory to check for existing progress
     * @param string $label Label used to identify related crawl directories
     * @return CrawlerConfig Updated configuration with continuation logic applied
     */
    public function applyUrlContinuation(
        CrawlerConfig $config,
        UrlProcessingOptions $urlOptions,
        bool $shouldContinue,
        int $startFromIndex,
        string $outputDir,
        string $label
    ): CrawlerConfig {
        // If not continuing or starting from index 1, handle fresh crawl
        if (!$shouldContinue || $startFromIndex <= 1) {
            // No continuation needed, but handle local file URLs
            if ($urlOptions->isLocal()) {
                $cleanUrls = $urlOptions->sitemapUrls;

                // Limit URLs to maxPages if specified
                if ($config->maxPages > 0) {
                    $cleanUrls = array_slice($cleanUrls, 0, $config->maxPages);
                }

                return new CrawlerConfig(
                    url: $config->url,
                    maxPages: $config->maxPages,
                    outputDir: $config->outputDir,
                    label: $config->label,
                    skipImages: $config->skipImages,
                    startFromIndex: $config->startFromIndex,
                    incompleteDirectories: $config->incompleteDirectories,
                    emptyDirectoriesToReuse: $config->emptyDirectoriesToReuse,
                    sourceType: $config->sourceType,
                    imageExceptions: $config->imageExceptions,
                    dateSelector: $config->dateSelector,
                    urls: $cleanUrls,
                    isLocalFile: true
                );
            }

            return $config;
        }

        // Calculate continuation offset based on existing directories
        $existingDirs = $this->directoryService->getExistingDirectories($outputDir, $label);
        $continueOffset = $this->progressService->calculateContinueOffset(
            $outputDir,
            $label,
            $urlOptions->sourceType,
            $urlOptions->sitemapUrls,
            $existingDirs
        );

        // For local file sources, slice the URL array to skip processed URLs
        if ($urlOptions->isLocal()) {
            $cleanUrls = array_slice($urlOptions->sitemapUrls, $continueOffset);

            // Apply maxPages limit to remaining URLs
            if ($config->maxPages > 0) {
                $cleanUrls = array_slice($cleanUrls, 0, $config->maxPages);
            }

            return new CrawlerConfig(
                url: $config->url,
                maxPages: $config->maxPages,
                outputDir: $config->outputDir,
                label: $config->label,
                skipImages: $config->skipImages,
                startFromIndex: $config->startFromIndex,
                incompleteDirectories: $config->incompleteDirectories,
                emptyDirectoriesToReuse: $config->emptyDirectoriesToReuse,
                sourceType: $config->sourceType,
                imageExceptions: $config->imageExceptions,
                dateSelector: $config->dateSelector,
                urls: $cleanUrls,
                isLocalFile: true
            );
        }

        // For non-local sources (sitemap/direct), pass continueOffset to the crawler
        return new CrawlerConfig(
            url: $config->url,
            maxPages: $config->maxPages,
            outputDir: $config->outputDir,
            label: $config->label,
            skipImages: $config->skipImages,
            startFromIndex: $config->startFromIndex,
            incompleteDirectories: $config->incompleteDirectories,
            emptyDirectoriesToReuse: $config->emptyDirectoriesToReuse,
            sourceType: $config->sourceType,
            imageExceptions: $config->imageExceptions,
            dateSelector: $config->dateSelector,
            urls: $config->urls,
            isLocalFile: $config->isLocalFile,
            continueOffset: $continueOffset
        );
    }
}
