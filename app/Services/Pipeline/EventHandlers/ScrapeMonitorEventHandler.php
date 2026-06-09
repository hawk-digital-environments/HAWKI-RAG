<?php

declare(strict_types=1);

namespace App\Services\Pipeline\EventHandlers;

use App\Models\PipelineJob;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventNormalizer;
use App\Services\Pipeline\Events\PipelineEventStateService;
use App\Services\Pipeline\ScrapeMonitoring\ScrapeMonitorCompletionHandler;
use App\Services\Pipeline\ScrapeMonitoring\ScrapeMonitorFailureHandler;
use App\Services\Pipeline\ScrapeMonitoring\ScrapeMonitorRetryScheduler;
use App\Services\Pipeline\ScrapeMonitoring\ScrapeMonitorStatusReader;
use App\Services\Pipeline\State\PipelineStateService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
class ScrapeMonitorEventHandler implements PipelineEventHandler
{
    public function __construct(
        private readonly PipelineEventStateService $state,
        private readonly PipelineStateService $pipelineState,
        private readonly PipelineEventNormalizer $normalizer,
        private readonly ScrapeMonitorStatusReader $statuses,
        private readonly ScrapeMonitorCompletionHandler $completion,
        private readonly ScrapeMonitorFailureHandler $failures,
        private readonly ScrapeMonitorRetryScheduler $retries,
    ) {
    }

    public function eventTypes(): array
    {
        return [
            PipelineEvent::SCRAPE_MONITOR_REQUESTED,
        ];
    }

    public function handle(array $event): void
    {
        $event = $this->normalizer->normalize(PipelineEvent::SCRAPE_MONITOR_REQUESTED, $event);

        if ($this->pipelineState->isStageCompleted((string) $event['job_id'], PipelineStateService::STAGE_CONVERT)) {
            return;
        }

        $snapshot = $this->statuses->read($event);

        if (! $snapshot->success) {
            $this->failures->statusReadFailure($event, $snapshot);

            return;
        }

        if ($snapshot->completed()) {
            $this->completion->complete($event, $snapshot);

            return;
        }

        if ($snapshot->terminalFailure()) {
            $this->failures->failedCrawlerStatus($event, $snapshot);

            return;
        }

        $this->retries->stillRunning($event, $snapshot);
    }

    public function failed(array $event, \Throwable $error, int $retryCount, int $maxRetries): void
    {
        $retryable = $retryCount < $maxRetries;
        $this->state->upsertJob($event, $retryable ? PipelineJob::STATUS_RUNNING : PipelineJob::STATUS_FAILED, [
            'retry_count' => $retryCount,
            'max_retries' => $maxRetries,
            'retry_scheduled' => $retryable,
            'error_type' => class_basename($error),
            'error_message' => $error->getMessage(),
        ]);
    }
}
