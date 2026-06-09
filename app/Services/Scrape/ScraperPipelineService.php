<?php

declare(strict_types=1);

namespace App\Services\Scrape;

use App\Services\Scrape\Data\ScrapeContext;
use App\Services\Scrape\Data\ScrapeJobRequest;
use App\Services\Scrape\Data\ScrapeRequestResult;
use App\Services\Scrape\Pipeline\ScrapeContextBuilder;
use App\Services\Scrape\Pipeline\ScrapeExecutionService;
use App\Services\Scrape\Pipeline\ScrapePipelineCancellationClient;
use App\Services\Scrape\Pipeline\ScrapePipelineStateReporter;
use App\Services\Scrape\Validation\ScrapeValidationService;
use App\Services\Storage\StorageService;
use Illuminate\Container\Attributes\Singleton;
use Throwable;

#[Singleton]
class ScraperPipelineService
{
    public function __construct(
        private ScrapeValidationService $validationService,
        private ScrapeExecutionService $executionService,
        private ScrapeContextBuilder $contexts,
        private StorageService $storageService,
        private ScrapePipelineStateReporter $stateReporter,
        private ScrapePipelineCancellationClient $cancellations,
    ) {
    }

    /**
     * Execute a crawler job through the complete pipeline.
     *
     * @param  callable|null  $outputCallback  Optional callback for streaming crawler output
     */
    public function execute(
        ScrapeJobRequest $request,
        ?callable $outputCallback = null
    ): ScrapeRequestResult {
        $context = $this->contexts->buildFromRequest($request);
        $this->stateReporter->started($context);

        try {
            $this->executeValidation($context);
            if ($context->hasErrors()) {
                $this->stateReporter->validationFailed($context);

                return $this->buildFailureResult($context);
            }

            $this->executeExecution($context, $outputCallback);
            if ($context->hasErrors()) {
                $this->stateReporter->failedAfterExecution($context);

                return $this->buildFailureResult($context);
            }

            $this->stateReporter->submitted($context);

            return ScrapeRequestResult::success(
                $context->jobId,
                $context->getStage(),
            );
        } catch (Throwable $e) {
            $context->addError($e->getMessage());
            $this->stateReporter->unexpectedFailure($context, $e);

            return $this->buildFailureResult($context);
        }
    }

    private function executeValidation(ScrapeContext $context): void
    {
        $this->stateReporter->validationStarted($context);
        $validation = $this->validationService->validateResult($context->getRequest());

        if (! $validation->valid()) {
            foreach ($validation->errors as $error) {
                $context->addError($error);
            }
        }

        foreach ($validation->warnings as $warning) {
            $context->addWarning($warning);
        }

        if ($validation->valid()) {
            $this->stateReporter->validationSucceeded($context, $validation->warnings);
        }
    }

    private function executeExecution(ScrapeContext $context, ?callable $outputCallback = null): void
    {
        $this->stateReporter->executionStarted($context);
        $response = $this->executionService->execute($context->getRequest(), $outputCallback);

        if ($response['success']) {
            $this->stateReporter->executionSucceeded($context, $response['message'] ?? null);

            return;
        }

        $this->stateReporter->executionFailed($context, $response['message'] ?? 'Scrape execution failed.');
    }

    /**
     * @throws \Exception
     */
    public function readPipelineStatus(string $jobId): array
    {
        return $this->storageService->fetchJobReport($jobId, 'job_state');
    }

    public function stop(string $jobId): array
    {
        return $this->cancellations->stop($jobId);
    }

    private function buildFailureResult(ScrapeContext $context): ScrapeRequestResult
    {
        return ScrapeRequestResult::failure(
            jobId: $context->jobId,
            stage: $context->getStage(),
            errors: $context->getErrors(),
            warnings: $context->getWarnings()
        );
    }
}
