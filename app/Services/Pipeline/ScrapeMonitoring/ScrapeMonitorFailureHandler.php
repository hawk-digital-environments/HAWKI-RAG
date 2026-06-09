<?php

declare(strict_types=1);

namespace App\Services\Pipeline\ScrapeMonitoring;

use App\Models\PipelineJob;
use App\Services\Pipeline\State\PipelineStateService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeMonitorFailureHandler
{
    public function __construct(
        private PipelineStateService $pipelineState,
        private ScrapeMonitorFailurePublisher $failures,
        private ScrapeMonitorPolicy $policy,
        private ScrapeMonitorRetryScheduler $retries,
    ) {
    }

    public function statusReadFailure(array $event, ScrapeMonitorStatusSnapshot $snapshot): void
    {
        $failures = (int) ($event['metadata']['status_read_failures'] ?? 0) + 1;
        $maxFailures = $this->policy->maxStatusReadFailures();

        if ($failures < $maxFailures) {
            $this->pipelineState->updateStage((string) $event['job_id'], PipelineStateService::STAGE_SCRAPE, [
                'status' => PipelineJob::STATUS_RUNNING,
                'metadata' => [
                    'crawlerStatus' => $snapshot->crawlerStatus ?: 'unknown',
                    'message' => $snapshot->message,
                    'source' => self::class,
                    'status_read_failures' => $failures,
                ],
            ]);

            $this->retries->reschedule($event, [
                'status_read_failures' => $failures,
                'last_status_read_error' => $snapshot->message,
            ], 'Crawl4AI status was not readable.');

            return;
        }

        $this->pipelineState->failStage((string) $event['job_id'], PipelineStateService::STAGE_SCRAPE, [
            'errors' => [[
                'message' => $snapshot->message,
                'status' => $snapshot->httpStatus,
                'updatedAt' => $this->policy->timestamp(),
            ]],
            'metadata' => ['source' => self::class],
        ]);

        $this->failures->publish($event, $snapshot->message, [
            'crawlerStatus' => $snapshot->crawlerStatus ?: 'unknown',
            'status' => $snapshot->httpStatus,
            'status_read_failures' => $failures,
        ]);
    }

    public function failedCrawlerStatus(array $event, ScrapeMonitorStatusSnapshot $snapshot): void
    {
        $message = (string) ($snapshot->data['message'] ?? "Crawl ended with status {$snapshot->crawlerStatus}.");

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
            'crawlerStatus' => $snapshot->crawlerStatus,
            'dataset_path' => $snapshot->datasetPath !== '' ? $snapshot->datasetPath : null,
            'counts' => $snapshot->counts,
        ]);
    }
}
