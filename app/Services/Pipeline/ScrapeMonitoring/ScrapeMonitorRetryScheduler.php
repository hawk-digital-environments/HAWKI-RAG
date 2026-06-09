<?php

declare(strict_types=1);

namespace App\Services\Pipeline\ScrapeMonitoring;

use App\Models\PipelineJob;
use App\Services\Pipeline\Events\PipelineEventBus;
use App\Services\Pipeline\Events\PipelineEventStateService;
use App\Services\Pipeline\State\PipelineStateService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeMonitorRetryScheduler
{
    public function __construct(
        private PipelineEventBus $events,
        private PipelineEventStateService $state,
        private PipelineStateService $pipelineState,
        private ScrapeMonitorFailurePublisher $failures,
        private ScrapeMonitorPolicy $policy,
    ) {
    }

    public function stillRunning(array $event, ScrapeMonitorStatusSnapshot $snapshot): void
    {
        $attempt = (int) ($event['metadata']['monitor_attempt'] ?? 0) + 1;
        $maxAttempts = $this->policy->maxMonitorAttempts();

        if ($attempt > $maxAttempts) {
            $message = "Crawl monitor exceeded {$maxAttempts} attempts.";
            $this->pipelineState->failStage((string) $event['job_id'], PipelineStateService::STAGE_SCRAPE, [
                'dataset_path' => $snapshot->datasetPath !== '' ? $snapshot->datasetPath : null,
                'counts' => $snapshot->counts,
                'errors' => [[
                    'message' => $message,
                    'updatedAt' => $this->policy->timestamp(),
                ]],
                'metadata' => ['source' => self::class],
            ]);
            $this->failures->publish($event, $message, [
                'crawlerStatus' => $snapshot->crawlerStatus ?: 'unknown',
                'dataset_path' => $snapshot->datasetPath !== '' ? $snapshot->datasetPath : null,
                'counts' => $snapshot->counts,
                'monitor_attempt' => $attempt,
            ]);

            return;
        }

        $this->pipelineState->updateStage((string) $event['job_id'], PipelineStateService::STAGE_SCRAPE, [
            'status' => PipelineJob::STATUS_RUNNING,
            'dataset_path' => $snapshot->datasetPath !== '' ? $snapshot->datasetPath : null,
            'counts' => $snapshot->counts,
            'metadata' => [
                'crawlerStatus' => $snapshot->crawlerStatus ?: 'unknown',
                'message' => $snapshot->data['message'] ?? null,
                'source' => self::class,
                'monitor_attempt' => $attempt,
            ],
        ]);

        $this->state->upsertJob($event, PipelineJob::STATUS_RUNNING, [
            'source' => self::class,
            'stage' => 'scrape_monitor_running',
            'monitor_attempt' => $attempt,
            'crawlerStatus' => $snapshot->crawlerStatus ?: 'unknown',
        ]);

        $this->reschedule($event, [
            'monitor_attempt' => $attempt,
            'crawlerStatus' => $snapshot->crawlerStatus ?: 'unknown',
        ], 'Crawl is still running.');
    }

    public function reschedule(array $event, array $metadata, string $reason): void
    {
        $this->events->publishDelayed(array_merge($event, [
            'status' => PipelineJob::STATUS_RUNNING,
            'metadata' => array_merge($event['metadata'] ?? [], $metadata, [
                'source' => self::class,
            ]),
        ]), $reason);
    }
}
