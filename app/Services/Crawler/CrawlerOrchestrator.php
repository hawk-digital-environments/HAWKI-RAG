<?php

namespace App\Services\Crawler;

use App\Services\Crawler\Data\CrawlerConfig;
use App\Services\Crawler\Data\CrawlerResult;
use App\Services\Crawler\Data\DirectoryAnalysis;
use App\Services\Crawler\Data\UrlProcessingOptions;

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
     * Run the full crawl process
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
            // Process URL
            $urlOptions = $this->configService->processUrl($url);

            // Setup output directory
            $outputDir = $this->directoryService->setupOutputDirectory();
            if (!$outputDir) {
                return new CrawlerResult(
                    success: false,
                    output: '',
                    error: 'Could not create or find the output directory.'
                );
            }

            // Analyze existing data
            $existingDirs = $this->directoryService->getExistingDirectories($outputDir, $label);
            $directoryAnalysis = $this->directoryService->scanDirectoriesForCompleteness($outputDir, $label);

            // Determine continuation strategy
            $shouldContinue = false;
            $startFromIndex = 1;

            if (!empty($existingDirs)) {
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
                    [$shouldContinue, $startFromIndex] = $this->handleContinue(
                        $outputDir,
                        $label,
                        $existingDirs,
                        $directoryAnalysis
                    );
                } elseif ($action === 'restart') {
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

            // Build configuration
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

            // Apply URL continuation logic
            $config = $this->configService->applyUrlContinuation(
                $config,
                $urlOptions,
                $shouldContinue,
                $startFromIndex,
                $outputDir,
                $label
            );

            // Execute crawler
            $result = $this->executionService->execute($config);

            // Save progress if successful
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
            return new CrawlerResult(
                success: false,
                output: '',
                error: $e->getMessage()
            );
        }
    }

    /**
     * Handle continue logic
     */
    private function handleContinue(
        string $outputDir,
        string $label,
        array $existingDirs,
        DirectoryAnalysis $directoryAnalysis
    ): array {
        $maxExistingDir = max($existingDirs);
        $startFromIndex = $maxExistingDir + 1;

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
     * Handle restart logic
     */
    private function handleRestart(string $outputDir, string $label): void
    {
        $crawlDir = "$outputDir/$label";

        if (is_dir($crawlDir)) {
            $this->directoryService->emptyDirectory($crawlDir);
        }

        $this->progressService->deleteProgress($label);
    }

    /**
     * Get analysis of existing directories
     */
    public function analyzeExistingData(string $outputDir, string $label): DirectoryAnalysis
    {
        return $this->directoryService->scanDirectoriesForCompleteness($outputDir, $label);
    }

    /**
     * Process URL without executing crawl
     */
    public function processUrl(string $url): UrlProcessingOptions
    {
        return $this->configService->processUrl($url);
    }
}
