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
        private ScrapePipelineStatePayloadBuilder $payloads,
        private CacheRepository $cache,
        private ClockInterface $clock = new Clock,
    ) {
    }

    public function started(ScrapeContext $context): void
    {
        $request = $context->getRequest();
        $this->pipelineState->startStage($context->jobId, PipelineStateService::STAGE_SCRAPE, $this->payloads->initialized($context));
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
        $this->pipelineState->updateStage($context->jobId, PipelineStateService::STAGE_SCRAPE, $this->payloads->runningSubStage('validation'));
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
            'error_message' => $this->payloads->errorSummary($context),
            'errors' => $context->getErrors(),
            'warnings' => $context->getWarnings(),
        ]);
        $this->pipelineState->failStage($context->jobId, PipelineStateService::STAGE_SCRAPE, $this->payloads->failed($context));
    }

    public function executionStarted(ScrapeContext $context): void
    {
        $context->setStage('execution');
        $this->pipelineState->updateStage($context->jobId, PipelineStateService::STAGE_SCRAPE, $this->payloads->runningSubStage('execution'));
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
            'error_message' => $this->payloads->errorSummary($context),
            'errors' => $context->getErrors(),
            'warnings' => $context->getWarnings(),
        ]);
        $this->pipelineState->failStage($context->jobId, PipelineStateService::STAGE_SCRAPE, $this->payloads->failed($context));
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
        $this->pipelineState->updateStage($context->jobId, PipelineStateService::STAGE_SCRAPE, $this->payloads->submitted($context));
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
        $this->pipelineState->failStage($context->jobId, PipelineStateService::STAGE_SCRAPE, $this->payloads->unexpectedFailure($context, $exception));
    }
}
