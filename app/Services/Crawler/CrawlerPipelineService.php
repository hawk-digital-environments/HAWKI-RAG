<?php

namespace App\Services\Crawler;

use App\Services\Crawler\Data\CrawlerContext;
use App\Services\Crawler\Data\CrawlerJobRequest;
use App\Services\Crawler\Data\CrawlerJobResult;
use App\Services\Crawler\Events\CrawlerEventService;
use App\Services\Crawler\Pipeline\CrawlerConfigurationService;
use App\Services\Crawler\Pipeline\CrawlerDirectoryService;
use App\Services\Crawler\Pipeline\CrawlerExecutionService;
use App\Services\Crawler\Pipeline\CrawlerProgressService;
use App\Services\Crawler\Pipeline\CrawlerUrlService;
use App\Services\Crawler\Storage\CrawlerStorageService;
use App\Services\Crawler\Validation\CrawlerValidationService;
use App\Services\Crawler\Validation\HostFilterService;

/**
 * Main gateway service for the crawler pipeline.
 *
 * This service orchestrates the complete crawler pipeline from start to finish,
 * coordinating all services and managing the flow of data through pipeline stages.
 * It provides a clean, API-ready interface for executing crawler jobs without
 * console dependencies.
 *
 * Pipeline Stages:
 * 1. Validation - Validate input and check business rules
 * 2. Configuration - Process URL and build configuration
 * 3. Pre-Execution - Analyze existing data and prepare
 * 4. Execution - Run the crawler
 * 5. Post-Processing - Process results
 * 6. Storage - Persist to database
 * 7. Finalization - Cleanup and result compilation
 */
class CrawlerPipelineService
{
    /**
     * Strategy for handling existing data.
     */
    public const STRATEGY_CONTINUE = 'continue';
    public const STRATEGY_RESTART = 'restart';
    public const STRATEGY_CANCEL = 'cancel';

    public function __construct(
        private CrawlerValidationService $validationService,
        private HostFilterService $hostFilterService,
        private CrawlerConfigurationService $configService,
        private CrawlerDirectoryService $directoryService,
        private CrawlerProgressService $progressService,
        private CrawlerUrlService $urlService,
        private CrawlerExecutionService $executionService,
        private CrawlerStorageService $storageService,
        private CrawlerEventService $eventService,
    ) {}

    /**
     * Execute a crawler job through the complete pipeline.
     *
     * This is the main entry point for all crawler operations. It processes
     * the job request through all pipeline stages and returns a structured result.
     *
     * @param CrawlerJobRequest $request Job request with all parameters
     * @param string $existingDataStrategy Strategy for handling existing data
     * @param bool $storeInDatabase Whether to store results in database
     * @param callable|null $outputCallback Optional callback for streaming crawler output
     * @return CrawlerJobResult Complete job result
     */
    public function execute(
        CrawlerJobRequest $request,
        string $existingDataStrategy = self::STRATEGY_CONTINUE,
        bool $storeInDatabase = false,
        ?callable $outputCallback = null
    ): CrawlerJobResult {
        // Create context to carry state through the pipeline
        $context = new CrawlerContext($request);

        try {
            // Stage 1: Validation
            $this->executeValidation($context);
            if ($context->hasErrors()) {
                return $this->buildFailureResult($context);
            }

            // Stage 2: Configuration
            $this->executeConfiguration($context);
            if ($context->hasErrors()) {
                return $this->buildFailureResult($context);
            }

            // Stage 3: Pre-Execution (analyze existing data)
            $this->executePreExecution($context, $existingDataStrategy);
            if ($context->hasErrors()) {
                return $this->buildFailureResult($context);
            }

            // Stage 4: Execution (run crawler)
            $this->executeExecution($context, $outputCallback);
            if ($context->hasErrors()) {
                return $this->buildFailureResult($context);
            }

            // Stage 5: Post-Processing
            $this->executePostProcessing($context);

            // Stage 6: Storage (optional)
            if ($storeInDatabase) {
                $this->executeStorage($context);
            }

            // Stage 7: Finalization
            $this->executeFinalization($context);

            // Dispatch completion event
            $this->eventService->pipelineCompleted($context);

            return CrawlerJobResult::fromContext($context);

        } catch (\Throwable $e) {
            $context->addError($e->getMessage());
            $this->eventService->error($context, $e->getMessage(), $e);
            return $this->buildFailureResult($context);
        }
    }

    /**
     * Stage 1: Validation
     *
     * Validates the job request and checks business rules.
     * @param CrawlerContext $context
     * @return void
     */
    private function executeValidation(CrawlerContext $context): void
    {
        $context->setStage('validation');
        $this->eventService->validationStarted($context);

        // Validate request
        $isValid = $this->validationService->validate($context->request);

        if (!$isValid) {
            foreach ($this->validationService->getErrors() as $error) {
                $context->addError($error, 'validation');
            }
        }

        foreach ($this->validationService->getWarnings() as $warning) {
            $context->addWarning($warning, 'validation');
        }

        // Check forbidden hosts for direct URLs
        if ($isValid && !$this->isLocalFile($context->request->url)) {
            if ($this->hostFilterService->isHostForbidden($context->request->url)) {
                $context->addError('URL is from a forbidden host.', 'validation');
                $isValid = false;
            }
        }

        $this->eventService->validationCompleted($context, $isValid);
    }

    /**
     * Stage 2: Configuration
     *
     * Processes URL and builds crawler configuration.
     * @param CrawlerContext $context
     * @return void
     */
    private function executeConfiguration(CrawlerContext $context): void
    {
        $context->setStage('configuration');
        $this->eventService->configurationStarted($context);

        try {
            // Process URL to determine type and extract info
            $urlOptions = $this->configService->processUrl($context->request->url);
            $context->urlOptions = $urlOptions;

            // Filter forbidden hosts from local file URLs
            if ($urlOptions->isLocal() && !empty($urlOptions->sitemapUrls)) {
                $originalCount = count($urlOptions->sitemapUrls);
                $filteredUrls = $this->hostFilterService->filterForbiddenHosts($urlOptions->sitemapUrls);
                $filteredCount = $originalCount - count($filteredUrls);

                if ($filteredCount > 0) {
                    $context->addWarning(
                        "Filtered out {$filteredCount} URLs from forbidden hosts.",
                        'configuration'
                    );
                }

                if (empty($filteredUrls)) {
                    $context->addError(
                        'No valid URLs remaining after filtering forbidden hosts.',
                        'configuration'
                    );
                    return;
                }

                // Update URL options with filtered URLs
                $context->urlOptions = new \App\Services\Crawler\Data\UrlProcessingOptions(
                    url: $urlOptions->url,
                    isLocalFile: $urlOptions->isLocalFile,
                    baseUrl: $urlOptions->baseUrl,
                    sourceType: $urlOptions->sourceType,
                    sitemapUrls: $filteredUrls
                );
            }

            $context->addMetadata('urlType', $urlOptions->sourceType);
            $context->addMetadata('urlCount', count($urlOptions->sitemapUrls));

            $this->eventService->configurationCompleted($context);

        } catch (\Throwable $e) {
            $context->addError("Configuration failed: {$e->getMessage()}", 'configuration');
        }
    }

    /**
     * Stage 3: Pre-Execution
     *
     * Analyzes existing data and prepares for execution.
     * @param CrawlerContext $context
     * @param string $strategy
     * @return void
     */
    private function executePreExecution(CrawlerContext $context, string $strategy): void
    {
        $context->setStage('pre_execution');

        // Setup output directory
        $outputDir = $context->request->outputDir ?: $this->directoryService->setupOutputDirectory();
        if (!$outputDir) {
            $context->addError('Could not create or find output directory.', 'pre_execution');
            return;
        }

        $label = $context->request->label;

        // Analyze existing directories
        $existingDirs = $this->directoryService->getExistingDirectories($outputDir, $label);
        $analysis = $this->directoryService->scanDirectoriesForCompleteness($outputDir, $label);
        $context->analysis = $analysis;

        if (!empty($existingDirs)) {
            $this->eventService->existingDataFound($context);

            // Handle existing data based on strategy
            if ($strategy === self::STRATEGY_CANCEL) {
                $context->addError('Operation cancelled due to existing data.', 'pre_execution');
                return;
            }

            if ($strategy === self::STRATEGY_RESTART) {
                $this->handleRestart($outputDir, $label);
                $shouldContinue = false;
                $startFromIndex = 1;
            } else {
                // STRATEGY_CONTINUE
                [$shouldContinue, $startFromIndex] = $this->handleContinue(
                    $outputDir,
                    $label,
                    $existingDirs,
                    $analysis
                );
            }
        } else {
            $shouldContinue = false;
            $startFromIndex = 1;
        }

        // Build configuration
        $config = $this->configService->buildConfig(
            $context->urlOptions,
            $outputDir,
            $label,
            $context->request->maxPages,
            $context->request->skipImages,
            $startFromIndex,
            $analysis,
            $shouldContinue,
            $context->request->imageExceptions,
            $context->request->dateSelector
        );

        // Apply URL continuation logic
        $config = $this->configService->applyUrlContinuation(
            $config,
            $context->urlOptions,
            $shouldContinue,
            $startFromIndex,
            $outputDir,
            $label
        );

        $context->config = $config;
    }

    /**
     * Stage 4: Execution
     *
     * Executes the crawler with the built configuration.
     */
    private function executeExecution(CrawlerContext $context, ?callable $outputCallback = null): void
    {
        $context->setStage('execution');
        $this->eventService->executionStarted($context);

        $result = $this->executionService->execute($context->config, $outputCallback);
        $context->result = $result;

        if ($result->isSuccessful()) {
            $this->executionService->saveProgress(
                $context->config,
                $context->request->label,
                $context->config->startFromIndex,
                count($context->config->incompleteDirectories) > 0,
                $context->urlOptions->sitemapUrls,
                $this->progressService,
                $this->urlService,
                $this->directoryService
            );

            $this->eventService->executionCompleted($context, true);
        } else {
            $context->addError($result->error ?? 'Execution failed.', 'execution');
            $this->eventService->executionCompleted($context, false);
        }
    }

    /**
     * Stage 5: Post-Processing
     *
     * Processes the results after execution.
     */
    private function executePostProcessing(CrawlerContext $context): void
    {
        $context->setStage('post_processing');

        // Add any post-processing logic here (e.g., image optimization, PDF conversion)
        // For now, just collect statistics

        if ($context->config) {
            $finalDirs = $this->directoryService->getExistingDirectories(
                $context->config->outputDir,
                $context->config->label
            );
            $context->addMetadata('totalDirectories', count($finalDirs));
        }
    }

    /**
     * Stage 6: Storage
     *
     * Persists results to the database.
     */
    private function executeStorage(CrawlerContext $context): void
    {
        $context->setStage('storage');
        $this->eventService->storageStarted($context);

        $storedCount = $this->storageService->storeResults($context);
        $context->addMetadata('storedPages', $storedCount);

        $this->eventService->storageCompleted($context);
    }

    /**
     * Stage 7: Finalization
     *
     * Finalizes the pipeline and prepares the result.
     */
    private function executeFinalization(CrawlerContext $context): void
    {
        $context->setStage('finalization');

        $context->addMetadata('endTime', now());

        // Calculate duration
        if ($context->getMetadata('startTime')) {
            $duration = $context->getMetadata('endTime')->diffInSeconds($context->getMetadata('startTime'));
            $context->addMetadata('durationSeconds', $duration);
        }
    }

    /**
     * Handle continuation of existing crawl.
     */
    private function handleContinue(
        string $outputDir,
        string $label,
        array $existingDirs,
        $analysis
    ): array {
        $maxExistingDir = max($existingDirs);
        $startFromIndex = $maxExistingDir + 1;

        if ($analysis->hasIncomplete()) {
            $this->directoryService->clearIncompleteDirectories(
                $outputDir,
                $label,
                $analysis->incomplete
            );
        }

        return [true, $startFromIndex];
    }

    /**
     * Handle restart of crawl.
     */
    private function handleRestart(string $outputDir, string $label): void
    {
        $this->directoryService->deleteLabel($label);
        $this->progressService->deleteProgress($label);
    }

    /**
     * Build a failure result from context.
     */
    private function buildFailureResult(CrawlerContext $context): CrawlerJobResult
    {
        return CrawlerJobResult::failure(
            jobId: $context->request->getJobId(),
            errors: $context->getErrors(),
            statistics: [],
            metadata: $context->metadata
        );
    }

    /**
     * Check if URL is a local file.
     */
    private function isLocalFile(string $url): bool
    {
        return file_exists($url) && is_readable($url);
    }

    /**
     * Get the event service for external listeners.
     */
    public function getEventService(): CrawlerEventService
    {
        return $this->eventService;
    }
}
