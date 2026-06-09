<?php

declare(strict_types=1);

namespace App\Services\Scrape\Pipeline;

use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Pipeline\State\PipelineStateService;
use App\Services\Scrape\Data\ScrapeContext;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Throwable;

#[Singleton]
readonly class ScrapePipelineStateReporter
{
    public function __construct(
        private PipelineStateService $pipelineState,
        private PipelineStageLogger $logger,
        private CacheRepository $cache,
        private ClockInterface $clock = new Clock,
    ) {
    }

    public function started(ScrapeContext $context): void
    {
        $request = $context->getRequest();
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
    }

    public function validationStarted(ScrapeContext $context): void
    {
        $context->setStage('validation');
        $this->pipelineState->updateStage($context->jobId, PipelineStateService::STAGE_SCRAPE, [
            'status' => 'running',
            'metadata' => ['subStage' => 'validation'],
        ]);
    }

    public function validationSucceeded(ScrapeContext $context, array $warnings): void
    {
        $this->logger->success('scrape', [
            'job_id' => $context->jobId,
            'source_url' => $context->getRequest()->url,
            'pipeline_stage' => 'validation',
            'warnings' => $warnings,
        ]);
    }

    public function validationFailed(ScrapeContext $context): void
    {
        $request = $context->getRequest();
        $this->logger->validationFailed('scrape', [
            'job_id' => $context->jobId,
            'source_url' => $request->url,
            'pipeline_stage' => $context->getStage(),
            'error_message' => $this->errorSummary($context),
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
    }

    public function executionStarted(ScrapeContext $context): void
    {
        $context->setStage('execution');
        $this->pipelineState->updateStage($context->jobId, PipelineStateService::STAGE_SCRAPE, [
            'status' => 'running',
            'metadata' => ['subStage' => 'execution'],
        ]);
    }

    public function executionSucceeded(ScrapeContext $context, ?string $message): void
    {
        $context->setStage('process_submitted');
        $this->logger->success('scrape', [
            'job_id' => $context->jobId,
            'source_url' => $context->getRequest()->url,
            'pipeline_stage' => 'execution',
            'message' => $message,
        ]);
    }

    public function executionFailed(ScrapeContext $context, string $message): void
    {
        $context->setStage('failed');
        $context->addError($message);
        $this->logger->failed('scrape', [
            'job_id' => $context->jobId,
            'source_url' => $context->getRequest()->url,
            'pipeline_stage' => 'execution',
            'error_message' => $message,
        ]);
    }

    public function failedAfterExecution(ScrapeContext $context): void
    {
        $request = $context->getRequest();
        $this->logger->failed('scrape', [
            'job_id' => $context->jobId,
            'source_url' => $request->url,
            'pipeline_stage' => $context->getStage(),
            'error_message' => $this->errorSummary($context),
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
    }

    public function submitted(ScrapeContext $context): void
    {
        $request = $context->getRequest();
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
    }

    public function unexpectedFailure(ScrapeContext $context, Throwable $exception): void
    {
        $request = $context->getRequest();
        $this->logger->failed('scrape', [
            'job_id' => $context->jobId,
            'source_url' => $request->url,
            'pipeline_stage' => $context->getStage(),
            'error_message' => $exception->getMessage(),
            'exception' => $exception,
        ]);
        $this->pipelineState->failStage($context->jobId, PipelineStateService::STAGE_SCRAPE, [
            'dataset_path' => $request->outputDir,
            'source_url' => $request->url,
            'label' => $request->label,
            'errors' => $context->getErrors(),
            'metadata' => [
                'subStage' => $context->getStage(),
                'exception' => get_class($exception),
            ],
        ]);
    }

    private function errorSummary(ScrapeContext $context): string
    {
        return implode('; ', array_map(
            static fn ($error) => is_array($error) ? (string) ($error['message'] ?? json_encode($error)) : (string) $error,
            $context->getErrors()
        ));
    }
}
