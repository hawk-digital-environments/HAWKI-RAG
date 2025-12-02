<?php

namespace App\Services\ScrapeService;

use App\Services\ScrapeService\Data\ScrapeContext;
use App\Services\ScrapeService\Data\ScrapeJobRequest;
use App\Services\ScrapeService\Data\ScrapeJobResult;
use App\Services\ScrapeService\Pipeline\ScrapeExecutionService;
use App\Services\ScrapeService\Pipeline\ScrapeContextBuilder;
use App\Services\ScrapeService\Validation\ScrapeValidationService;
use App\Services\StorageService\StorageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ScraperPipelineService
{

    public function __construct(
        private readonly ScrapeValidationService $validationService,
        private readonly ScrapeExecutionService  $executionService,
        private readonly StorageService $storageService,
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
        $context = ScrapeContextBuilder::buildFromRequest($request);

        try {
            // Stage 1: Validation
            $this->executeValidation($context);
            if ($context->hasErrors()) {
                return $this->buildFailureResult($context);
            }

            // Stage 2: Execution
            $crawlerJobId = $this->executeExecution($context, $outputCallback);
            if ($context->hasErrors()) {
                return $this->buildFailureResult($context);
            }

            // Update process with the actual job_id from the crawler
            if ($crawlerJobId) {
                $context->process->update(['job_id' => $crawlerJobId]);
                $context->process->refresh();
            }

            //save process in cache so we don't need to rebuild it from database.
            // Use the process's job_id (which is now the crawler's job_id after update)
            Cache::put("scrape_process:{$context->process->job_id}", $context->process, now()->addMinutes(10));
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
        $isValid = $this->validationService->validate($context->config);

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
     * Returns the job_id from the crawler if successful, null otherwise.
     */
    private function executeExecution(ScrapeContext $context, ?callable $outputCallback = null): ?string
    {
        $context->setStage('execution');
//        $this->eventService->executionStarted($context);

        // Execute the crawler - persistent Redis subscriber is already listening
        $result = $this->executionService->execute($context->config, $outputCallback);

        if($result->event === 'job_submitted'){
            $context->setStage('process_submitted');
            return $result->jobId; // Return the crawler's job_id
        }
        else {
            $context->addError( 'Execution failed.', 'execution');
//          $this->eventService->executionCompleted($context, false);
            return null;
        }
    }


    //@todo decide between broadcasting or command reading.
    public function readPipelineStatus($jobId): array{
        return $this->storageService->fetchJobReport($jobId, 'job_state');
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
