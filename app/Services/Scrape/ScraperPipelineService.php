<?php

declare(strict_types=1);

namespace App\Services\Scrape;

use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Pipeline\State\PipelineStateService;
use App\Services\Scrape\Data\ScrapeContext;
use App\Services\Scrape\Data\ScrapeJobRequest;
use App\Services\Scrape\Data\ScrapeRequestResult;
use App\Services\Scrape\Pipeline\ScrapeContextBuilder;
use App\Services\Scrape\Pipeline\ScrapeExecutionService;
use App\Services\Scrape\Validation\ScrapeValidationService;
use App\Services\Storage\StorageService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Throwable;

#[Singleton]
class ScraperPipelineService
{
    public function __construct(
        private readonly ScrapeValidationService $validationService,
        private readonly ScrapeExecutionService $executionService,
        private readonly ScrapeContextBuilder $contexts,
        private readonly StorageService $storageService,
        private readonly PipelineStateService $pipelineState,
        private readonly PipelineStageLogger $logger,
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
        private readonly CacheRepository $cache,
        private readonly LoggerInterface $systemLogger,
        private readonly ClockInterface $clock = new Clock,
    ) {}

    /**
     * Execute a crawler job through the complete pipeline.
     *
     * This is the main entry point for all crawler operations. It processes
     * the job request through all pipeline stages and returns a structured result.
     *
     * @param  ScrapeJobRequest  $request  Job request with all parameters
     * @param  callable|null  $outputCallback  Optional callback for streaming crawler output
     * @return ScrapeRequestResult Complete job result
     */
    public function execute(
        ScrapeJobRequest $request,
        ?callable $outputCallback = null
    ): ScrapeRequestResult {
        // Create context to carry state through the pipeline
        $context = $this->contexts->buildFromRequest($request);
        $this->pipelineState->startStage($context->jobId, PipelineStateService::STAGE_SCRAPE, [
            'dataset_path' => $request->outputDir,
            'source_url' => $request->url,
            'label' => $request->label,
            'counts' => [
                'totalPages' => $request->maxPages,
                'pagesCrawled' => 0,
                'failedUrls' => 0,
            ],
            'metadata' => [
                'subStage' => 'initialized',
                'request' => $request->toArray(),
            ],
        ]);
        $this->logger->started('scrape', [
            'job_id' => $context->jobId,
            'source_url' => $request->url,
            'output_dir' => $request->outputDir,
            'pipeline_stage' => 'initialized',
        ]);

        try {
            // Stage 1: Validation
            $this->executeValidation($context);
            if ($context->hasErrors()) {
                $this->logger->validationFailed('scrape', [
                    'job_id' => $context->jobId,
                    'source_url' => $request->url,
                    'pipeline_stage' => $context->getStage(),
                    'error_message' => implode('; ', array_map(
                        static fn ($error) => is_array($error) ? (string) ($error['message'] ?? json_encode($error)) : (string) $error,
                        $context->getErrors()
                    )),
                    'errors' => $context->getErrors(),
                    'warnings' => $context->getWarnings(),
                ]);
                $this->pipelineState->failStage($context->jobId, PipelineStateService::STAGE_SCRAPE, [
                    'dataset_path' => $request->outputDir,
                    'source_url' => $request->url,
                    'label' => $request->label,
                    'errors' => $context->getErrors(),
                    'warnings' => $context->getWarnings(),
                    'metadata' => ['subStage' => $context->getStage()],
                ]);

                return $this->buildFailureResult($context);
            }

            // Stage 2: Execution
            $this->executeExecution($context, $outputCallback);
            if ($context->hasErrors()) {
                $this->logger->failed('scrape', [
                    'job_id' => $context->jobId,
                    'source_url' => $request->url,
                    'pipeline_stage' => $context->getStage(),
                    'error_message' => implode('; ', array_map(
                        static fn ($error) => is_array($error) ? (string) ($error['message'] ?? json_encode($error)) : (string) $error,
                        $context->getErrors()
                    )),
                    'errors' => $context->getErrors(),
                    'warnings' => $context->getWarnings(),
                ]);
                $this->pipelineState->failStage($context->jobId, PipelineStateService::STAGE_SCRAPE, [
                    'dataset_path' => $request->outputDir,
                    'source_url' => $request->url,
                    'label' => $request->label,
                    'errors' => $context->getErrors(),
                    'warnings' => $context->getWarnings(),
                    'metadata' => ['subStage' => $context->getStage()],
                ]);

                return $this->buildFailureResult($context);
            }

            $this->cache->put("scrape_process:{$context->jobId}", $context->process, $this->clock->now()->modify('+10 minutes'));
            $this->logger->success('scrape', [
                'job_id' => $context->jobId,
                'source_url' => $request->url,
                'output_dir' => $request->outputDir,
                'pipeline_stage' => $context->getStage(),
                'warnings' => $context->getWarnings(),
            ]);
            $this->pipelineState->updateStage($context->jobId, PipelineStateService::STAGE_SCRAPE, [
                'status' => 'running',
                'dataset_path' => $request->outputDir,
                'source_url' => $request->url,
                'label' => $request->label,
                'counts' => [
                    'totalPages' => $request->maxPages,
                    'pagesCrawled' => 0,
                    'failedUrls' => 0,
                ],
                'warnings' => $context->getWarnings(),
                'metadata' => [
                    'subStage' => $context->getStage(),
                    'message' => 'Crawl submitted to Crawl4AI.',
                ],
            ]);

            return ScrapeRequestResult::success(
                $context->jobId,
                $context->getStage(),
            );
        } catch (Throwable $e) {
            $context->addError($e->getMessage());
            $this->logger->failed('scrape', [
                'job_id' => $context->jobId,
                'source_url' => $request->url,
                'pipeline_stage' => $context->getStage(),
                'error_message' => $e->getMessage(),
                'exception' => $e,
            ]);
            $this->pipelineState->failStage($context->jobId, PipelineStateService::STAGE_SCRAPE, [
                'dataset_path' => $request->outputDir,
                'source_url' => $request->url,
                'label' => $request->label,
                'errors' => $context->getErrors(),
                'metadata' => [
                    'subStage' => $context->getStage(),
                    'exception' => get_class($e),
                ],
            ]);

            return $this->buildFailureResult($context);
        }
    }

    /**
     * Stage 1: Validation
     *
     * Validates the job request and checks business rules.
     */
    private function executeValidation(ScrapeContext $context): void
    {
        $context->setStage('validation');
        $this->pipelineState->updateStage($context->jobId, PipelineStateService::STAGE_SCRAPE, [
            'status' => 'running',
            'metadata' => ['subStage' => 'validation'],
        ]);

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
            $this->logger->success('scrape', [
                'job_id' => $context->jobId,
                'source_url' => $context->getRequest()->url,
                'pipeline_stage' => 'validation',
                'warnings' => $validation->warnings,
            ]);
        }
    }

    /**
     * Stage 4: Execution
     *
     * Executes the crawler with the built configuration.
     * Returns the job_id from the crawler if successful, null otherwise.
     *
     */
    private function executeExecution(ScrapeContext $context, ?callable $outputCallback = null): void
    {
        $context->setStage('execution');
        $this->pipelineState->updateStage($context->jobId, PipelineStateService::STAGE_SCRAPE, [
            'status' => 'running',
            'metadata' => ['subStage' => 'execution'],
        ]);

        // Execute the crawler and persist progress through the scrape pipeline.
        $response = $this->executionService->execute($context->getRequest(), $outputCallback);

        if ($response['success']) {
            $context->setStage('process_submitted');
            $this->logger->success('scrape', [
                'job_id' => $context->jobId,
                'source_url' => $context->getRequest()->url,
                'pipeline_stage' => 'execution',
                'message' => $response['message'] ?? null,
            ]);
        } else {
            $context->setStage('failed');
            $context->addError($response['message']);
            $this->logger->failed('scrape', [
                'job_id' => $context->jobId,
                'source_url' => $context->getRequest()->url,
                'pipeline_stage' => 'execution',
                'error_message' => $response['message'] ?? 'Scrape execution failed.',
            ]);
        }
    }

    /**
     * @throws \Exception
     */
    public function readPipelineStatus(string $jobId): array
    {
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

    public function stop(string $jobId): array
    {
        $context = $this->contexts->rebuildContext($jobId);

        $response = $this->http->timeout(300)
            ->retry(3, 1000)
            ->post($this->apiUrl()."/jobs/$jobId/cancel");

        $data = $response->json();
        $this->systemLogger->debug('scrape.pipeline.stop_response', ['response' => $data]);
        if ($data['success']) {
            $context->setStage('stopped');
            $context->addWarning('Process canceled at '.$this->clock->now()->format('Y-m-d H:i:s'));
        }

        return [
            'success' => $data['success'],
            'message' => $data['message'] ?? 'No message provided',
        ];
    }

    private function apiUrl(): string
    {
        return rtrim((string) $this->config->get('scraper.api_url'), '/');
    }
}
