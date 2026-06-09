<?php

declare(strict_types=1);

namespace App\Services\Scrape\Pipeline;

use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Scrape\Data\ScrapeContext;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
class ScrapeFinalizerService
{
    public function __construct(
        protected readonly PipelineStageLogger $logger,
        protected readonly ScrapeElementReconciler $elements,
        protected readonly CacheRepository $cache,
        protected readonly ClockInterface $clock = new Clock(),
    ) {}

    public function executeFinalization(ScrapeContext $context): void
    {
        try {
            $this->logger->started('scrape', [
                'job_id' => $context->jobId,
                'pipeline_stage' => 'finalization',
            ]);

            $this->elements->reconcile($context);
            $context->setStage('Scrape-Completed');
            $context->setStats('completed_at', $this->clock->now()->format(\DateTimeInterface::ATOM));
            $this->cache->forget("scrape_process:{$context->jobId}");
            $this->logger->success('scrape', [
                'job_id' => $context->jobId,
                'pipeline_stage' => 'finalization',
            ]);

        } catch (\Throwable $e) {
            $this->logger->failed('scrape', [
                'job_id' => $context->jobId,
                'pipeline_stage' => 'finalization',
                'error_message' => $e->getMessage(),
                'exception' => $e,
            ]);
            $context->addError('Finalization failed: '.$e->getMessage());
            throw $e;
        }
    }
}
