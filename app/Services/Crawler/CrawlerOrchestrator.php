<?php

namespace App\Services\Crawler;

use App\Services\Crawler\Data\CrawlerConfig;
use App\Services\Crawler\Data\CrawlerResult;
use App\Services\Crawler\Data\DirectoryAnalysis;
use App\Services\Crawler\Data\UrlProcessingOptions;
use App\Services\Crawler\Pipeline\CrawlerConfigurationService;
use App\Services\Crawler\Pipeline\CrawlerDirectoryService;
use App\Services\Crawler\Pipeline\CrawlerExecutionService;
use App\Services\Crawler\Pipeline\CrawlerProgressService;
use App\Services\Crawler\Pipeline\CrawlerUrlService;

class CrawlerOrchestrator
{
    public function __construct(
        private CrawlerConfigurationService $configService,
        private CrawlerExecutionService $executionService,
        private CrawlerDirectoryService $directoryService,
        private CrawlerProgressService $progressService,
        private CrawlerUrlService $urlService
    ) {}

    /**
     * Orchestrate the complete web crawling process from start to finish.
     *
     * This is the main entry point for crawling operations. It coordinates all phases:
     * 1. URL processing and validation
     * 2. Output directory setup
     * 3. Existing data analysis
     * 4. User decision handling (continue/restart/cancel)
     * 5. Configuration building
     * 6. Crawler execution
     * 7. Progress tracking
     *
     * The function supports resuming incomplete crawls and provides callbacks for
     * user interaction when existing data is found.
     *
     * @param string $url URL to crawl (can be direct URL, sitemap URL, or local file path)
     * @param string $label Label/prefix for organizing crawl output directories
     * @param int $maxPages Maximum number of pages to crawl (default: 100, 0 for unlimited)
     * @param bool $skipImages Whether to skip downloading images (default: false)
     * @param array|null $imageExceptions URLs of images to download even when skipImages is true
     * @param string|null $dateSelector CSS selector for extracting dates from pages
     * @param callable|null $shouldContinueCallback Callback to ask user what to do with existing data
     *                                              Receives ($existingDirs, $directoryAnalysis)
     *                                              Returns: 'continue', 'restart', or 'cancel'
     * @param callable|null $shouldRestartCallback Callback to confirm restart operation
     *                                             Returns: bool (true to confirm, false to cancel)
     * @return CrawlerResult Result object containing success status, output, and any errors
     */
    public function crawl(
        string $url,
        string $label,
        int $maxPages = 100,
        bool $skipImages = false,
        ?array $imageExceptions = null,
        ?string $dateSelector = null,
        ?callable $shouldContinueCallback = null,
        ?callable $shouldRestartCallback = null
    ): CrawlerResult {
        try {
            // Step 1: Process and validate the input URL
            $urlOptions = $this->configService->processUrl($url);

            // Step 2: Setup and verify output directory exists
            $outputDir = $this->directoryService->setupOutputDirectory();
            if (!$outputDir) {
                return new CrawlerResult(
                    success: false,
                    output: '',
                    error: 'Could not create or find the output directory.'
                );
            }

            // Step 3: Analyze existing crawl data for this label
            $existingDirs = $this->directoryService->getExistingDirectories($outputDir, $label);
            $directoryAnalysis = $this->directoryService->scanDirectoriesForCompleteness($outputDir, $label);

            // Step 4: Determine continuation strategy based on existing data
            $shouldContinue = false;
            $startFromIndex = 1;

            if (!empty($existingDirs)) {
                // Ask user what to do with existing data (via callback or default to continue)
                $action = $shouldContinueCallback
                    ? $shouldContinueCallback($existingDirs, $directoryAnalysis)
                    : 'continue';

                if ($action === 'cancel') {
                    return new CrawlerResult(
                        success: true,
                        output: 'Scrape operation cancelled by user.'
                    );
                }

                if ($action === 'continue') {
                    // Continue from where we left off
                    [$shouldContinue, $startFromIndex] = $this->handleContinue(
                        $outputDir,
                        $label,
                        $existingDirs,
                        $directoryAnalysis
                    );
                } elseif ($action === 'restart') {
                    // Confirm restart and clear existing data
                    $confirmed = $shouldRestartCallback ? $shouldRestartCallback() : false;

                    if (!$confirmed) {
                        return new CrawlerResult(
                            success: true,
                            output: 'Scrape operation cancelled by user.'
                        );
                    }

                    $this->handleRestart($outputDir, $label);
                    $shouldContinue = false;
                    $startFromIndex = 1;
                }
            }

            // Step 5: Build complete crawler configuration
            $config = $this->configService->buildConfig(
                $urlOptions,
                $outputDir,
                $label,
                $maxPages,
                $skipImages,
                $startFromIndex,
                $directoryAnalysis,
                $shouldContinue,
                $imageExceptions,
                $dateSelector
            );

            // Step 6: Apply URL continuation logic (handles offset calculation)
            $config = $this->configService->applyUrlContinuation(
                $config,
                $urlOptions,
                $shouldContinue,
                $startFromIndex,
                $outputDir,
                $label
            );

            // Step 7: Execute the crawler with the final configuration
            $result = $this->executionService->execute($config);

            // Step 8: Save progress for potential resumption
            if ($result->isSuccessful()) {
                $this->executionService->saveProgress(
                    $config,
                    $label,
                    $startFromIndex,
                    $shouldContinue,
                    $urlOptions->sitemapUrls,
                    $this->progressService,
                    $this->urlService,
                    $this->directoryService
                );
            }

            return $result;

        } catch (\Throwable $e) {
            // Catch any unexpected errors and return as failed result
            return new CrawlerResult(
                success: false,
                output: '',
                error: $e->getMessage()
            );
        }
    }

    /**
     * Handle the logic for continuing an existing crawl session.
     *
     * Determines the starting index for resumption by finding the highest existing
     * directory number and clearing any incomplete directories. Returns continuation
     * status and the index to start from.
     *
     * @param string $outputDir Base output directory
     * @param string $label Label identifying the crawl session
     * @param array $existingDirs Array of existing directory numbers
     * @param DirectoryAnalysis $directoryAnalysis Analysis of directory completeness
     * @return array [bool $shouldContinue, int $startFromIndex]
     */
    private function handleContinue(
        string $outputDir,
        string $label,
        array $existingDirs,
        DirectoryAnalysis $directoryAnalysis
    ): array {
        // Start from the next index after the highest existing directory
        $maxExistingDir = max($existingDirs);
        $startFromIndex = $maxExistingDir + 1;

        // Clean up any incomplete directories before continuing
        if ($directoryAnalysis->hasIncomplete()) {
            $this->directoryService->clearIncompleteDirectories(
                $outputDir,
                $label,
                $directoryAnalysis->incomplete
            );
        }

        return [true, $startFromIndex];
    }

    /**
     * Handle the logic for restarting a crawl from scratch.
     *
     * Clears all existing data for the specified label by emptying the crawl
     * directory and deleting the progress file. This prepares for a fresh crawl.
     *
     * @param string $outputDir Base output directory
     * @param string $label Label identifying the crawl session to restart
     * @return void
     */
    private function handleRestart(string $outputDir, string $label): void
    {
        $this->directoryService->deleteLabel($label);
        $this->progressService->deleteProgress($label);
    }

    /**
     * Analyze existing crawl data without executing a crawl.
     *
     * Scans directories for the specified label and returns an analysis of
     * their completeness status. Useful for checking crawl progress or
     * determining if a continuation is needed.
     *
     * @param string $outputDir Base output directory to analyze
     * @param string $label Label identifying the crawl session
     * @return DirectoryAnalysis Analysis containing complete, incomplete, and empty directories
     */
    public function analyzeExistingData(string $outputDir, string $label): DirectoryAnalysis
    {
        return $this->directoryService->scanDirectoriesForCompleteness($outputDir, $label);
    }

    /**
     * Process and validate a URL without executing the crawl.
     *
     * Useful for validating URLs and determining their type (local file, sitemap, or direct)
     * before starting a crawl operation. Returns processed URL options that can be
     * inspected or used for configuration building.
     *
     * @param string $url URL or file path to process
     * @return UrlProcessingOptions Processed URL information including type and extracted data
     */
    public function processUrl(string $url): UrlProcessingOptions
    {
        return $this->configService->processUrl($url);
    }
}
