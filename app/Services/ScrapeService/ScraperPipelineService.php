<?php

namespace App\Services\ScrapeService;

use App\Jobs\ProcessScrapeEvents;
use App\Services\ScrapeService\Data\ScrapeContext;
use App\Services\ScrapeService\Data\ScrapeJobRequest;
use App\Services\ScrapeService\Data\ScrapeJobResult;
use App\Services\ScrapeService\Pipeline\ScrapeExecutionService;
use App\Services\ScrapeService\Validation\ScrapeValidationService;
use Illuminate\Support\Facades\Log;

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
class ScraperPipelineService
{

    public function __construct(
        private readonly ScrapeValidationService $validationService,
        private readonly ScrapeExecutionService  $executionService,
    ) {}

    /**
     * Execute a crawler job through the complete pipeline.
     *
     * This is the main entry point for all crawler operations. It processes
     * the job request through all pipeline stages and returns a structured result.
     *
     * @param ScrapeJobRequest $request Job request with all parameters
     * @param callable|null $outputCallback Optional callback for streaming crawler output
     * @return ScrapeJobResult Complete job result
     */
    public function execute(
        ScrapeJobRequest $request,
        ?callable        $outputCallback = null
    ): ScrapeJobResult {
        // Create context to carry state through the pipeline
        $context = new ScrapeContext($request);

        try {
            // Stage 1: Validation
            $this->executeValidation($context);
            if ($context->hasErrors()) {
                return $this->buildFailureResult($context);
            }

            // Stage 2: Execution
            $this->executeExecution($context, $outputCallback);
            if ($context->hasErrors()) {
                return $this->buildFailureResult($context);
            }

            // Stage 3: Finalization
            $this->executeFinalization($context);

            // Dispatch completion event
//            $this->eventService->pipelineCompleted($context);

            return ScrapeJobResult::fromContext($context);

        } catch (\Throwable $e) {
            $context->addError($e->getMessage());
//            $this->eventService->error($context, $e->getMessage(), $e);
            Log::error($e->getMessage());
            return $this->buildFailureResult($context);
        }
    }

    /**
     * Stage 1: Validation
     *
     * Validates the job request and checks business rules.
     * @param ScrapeContext $context
     * @return void
     */
    private function executeValidation(ScrapeContext $context): void
    {
        $context->setStage('validation');
//        $this->eventService->validationStarted($context);

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

//        $this->eventService->validationCompleted($context, $isValid);
    }

    /**
     * Stage 4: Execution
     *
     * Executes the crawler with the built configuration.
     */
    private function executeExecution(ScrapeContext $context, ?callable $outputCallback = null): void
    {
        $context->setStage('execution');
//        $this->eventService->executionStarted($context);

        $result = $this->executionService->execute($context->request, $outputCallback);

        if($result->event === 'job_submitted'){
            $context->setStage('process_submitted');

            // Start listening for Redis events for this specific job
            $this->startEventListener($result->jobId);
        }
        else {
            $context->addError( 'Execution failed.', 'execution');
//          $this->eventService->executionCompleted($context, false);
        }
    }

    /**
     * Start Redis event listener for a specific job.
     *
     * Dispatches a background job that subscribes to Redis Pub/Sub
     * and processes events for the given job ID until completion.
     *
     * @param string $jobId The job ID to listen for
     * @return void
     */
    private function startEventListener(string $jobId): void
    {
        $channel = config('scrape.redis_channel', 'scrape-events');
        $maxWaitSeconds = config('scrape.max_job_duration', 3600); // 1 hour default

        // Dispatch the event listener job
        ProcessScrapeEvents::dispatch($jobId, $channel, $maxWaitSeconds);

        \Log::info("Started Redis event listener for job {$jobId}");
    }

    /**
     * Stage 7: Finalization
     *
     * Finalizes the pipeline and prepares the result.
     */
    private function executeFinalization(ScrapeContext $context): void
    {
        $context->setStage('finalization');
        $context->addMetadata('endTime', now());
        $context->setEndProcess();

        // Calculate duration
        if ($context->getMetadata('startTime')) {
            $duration = $context->getMetadata('endTime')->diffInSeconds($context->getMetadata('startTime'));
            $context->addMetadata('durationSeconds', $duration);
        }
    }

    /**
     * Build a failure result from context.
     */
    private function buildFailureResult(ScrapeContext $context): ScrapeJobResult
    {
        return ScrapeJobResult::failure(
            jobId: $context->jobId,
            errors: $context->getErrors(),
            statistics: [],
            metadata: $context->metadata
        );
    }
}
