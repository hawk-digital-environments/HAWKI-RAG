<?php

namespace App\Services\ScrapeService;

use App\Services\ScrapeService\Data\ScrapeContext;
use App\Services\ScrapeService\Data\ScrapeJobRequest;
use App\Services\ScrapeService\Data\ScrapeRequestResult;
use App\Services\ScrapeService\Pipeline\ScrapeExecutionService;
use App\Services\ScrapeService\Pipeline\ScrapeContextBuilder;
use App\Services\ScrapeService\Validation\ScrapeValidationService;
use App\Services\StorageService\StorageService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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
     * @return ScrapeRequestResult Complete job result
     */
    public function execute(
        ScrapeJobRequest $request,
        ?callable        $outputCallback = null
    ): ScrapeRequestResult
    {
        // Create context to carry state through the pipeline
        $context = ScrapeContextBuilder::buildFromRequest($request);

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

            Cache::put("scrape_process:{$context->jobId}", $context->process, now()->addMinutes(10));
            return ScrapeRequestResult::success(
                $context->jobId,
                $context->getStage(),
            );
        } catch (Throwable $e) {
            $context->addError($e->getMessage());
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

        // Validate request
        $isValid = $this->validationService->validate($context->getRequest());

        if (!$isValid) {
            foreach ($this->validationService->getErrors() as $error) {
                $context->addError($error);
            }
        }

        foreach ($this->validationService->getWarnings() as $warning) {
            $context->addWarning($warning);
        }
    }

    /**
     * Stage 4: Execution
     *
     * Executes the crawler with the built configuration.
     * Returns the job_id from the crawler if successful, null otherwise.
     * @throws ConnectionException
     */
    private function executeExecution(ScrapeContext $context, ?callable $outputCallback = null): void
    {
        $context->setStage('execution');

        // Execute the crawler - persistent Redis subscriber is already listening
        $response = $this->executionService->execute($context->getRequest(), $outputCallback);

        if($response['success']){
            $context->setStage('process_submitted');
        }
        else {
            $context->setStage('failed');
            $context->addError($response['message']);
        }
    }

    /**
     * @throws \Exception
     */
    public function readPipelineStatus($jobId): array{
        return $this->storageService->fetchJobReport($jobId, 'job_state');
    }


    /**
     * Build a failure result from context.
     */
    private function buildFailureResult(ScrapeContext $context): ScrapeRequestResult
    {
        return ScrapeRequestResult::failure(
            jobId: $context->jobId,
            stage: $context->getStage(),
            errors: $context->getErrors(),
            warnings: $context->getWarnings()
        );
    }


    public function stop($jobId): array
    {
        $context = ScrapeContextBuilder::rebuildContext($jobId);

        $response = Http::timeout(300)
            ->retry(3, 1000)
            ->post(config('scraper.api_url') . "/jobs/$jobId/cancel");

        $data = $response->json();
        Log::debug($data);
        if($data['success']){
            $context->setStage('stopped');
            $context->addWarning("Process canceled at " . now()->format('Y-m-d H:i:s'));
        }

        return [
            'success' => $data['success'],
            'message' => $data['message'] ?? 'No message provided',
        ];
    }
}
