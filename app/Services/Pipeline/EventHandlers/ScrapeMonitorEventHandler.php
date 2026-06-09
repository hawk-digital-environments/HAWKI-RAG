<?php

declare(strict_types=1);

namespace App\Services\Pipeline\EventHandlers;

use App\Models\PipelineJob;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventBus;
use App\Services\Pipeline\Events\PipelineEventNormalizer;
use App\Services\Pipeline\Events\PipelineEventStateService;
use App\Services\Pipeline\Repositories\PipelineJobRepository;
use App\Services\Pipeline\ScrapeMonitoring\ScrapeMonitorFailurePublisher;
use App\Services\Pipeline\ScrapeMonitoring\ScrapeMonitorOutputPublisher;
use App\Services\Pipeline\State\PipelineStageLogger;
use App\Services\Pipeline\State\PipelineStateService;
use App\Services\Scrape\ScrapeService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
class ScrapeMonitorEventHandler implements PipelineEventHandler
{
    public function __construct(
        private readonly PipelineEventBus $events,
        private readonly PipelineEventStateService $state,
        private readonly PipelineStateService $pipelineState,
        private readonly PipelineJobRepository $jobs,
        private readonly ScrapeService $scrapeService,
        private readonly PipelineStageLogger $logger,
        private readonly PipelineEventNormalizer $normalizer,
        private readonly ScrapeMonitorOutputPublisher $outputs,
        private readonly ScrapeMonitorFailurePublisher $failures,
    ) {}

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

        $result = $this->scrapeService->getCrawlerStatus((string) $event['job_id']);
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $crawlerStatus = strtolower((string) ($data['status'] ?? ''));
        $datasetPath = (string) ($data['output_directory'] ?? '');
        $counts = [
            'totalPages' => (int) ($data['total_pages'] ?? 0),
            'pagesCrawled' => (int) ($data['pages_crawled'] ?? 0),
            'failedUrls' => (int) ($data['failed_urls'] ?? 0),
        ];

        if (! ($result['success'] ?? false)) {
            $this->handleStatusReadFailure($event, $result, $crawlerStatus);

            return;
        }

        if ($crawlerStatus === 'completed') {
            $this->handleCompleted($event, $datasetPath, $counts, $data);

            return;
        }

        if (in_array($crawlerStatus, ['failed', 'cancelled', 'canceled', 'stopped'], true)) {
            $this->handleFailedStatus($event, $crawlerStatus, $datasetPath, $counts, $data);

            return;
        }

        $this->handleStillRunning($event, $crawlerStatus, $datasetPath, $counts, $data);
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

    private function handleStatusReadFailure(array $event, array $result, string $crawlerStatus): void
    {
        $failures = (int) ($event['metadata']['status_read_failures'] ?? 0) + 1;
        $maxFailures = max(1, (int) config('communication.rabbitmq.pipeline_events.scrape_monitor_status_read_retries', 5));
        $message = (string) ($result['message'] ?? 'Unable to read Crawl4AI status.');

        if ($failures < $maxFailures) {
            $this->pipelineState->updateStage((string) $event['job_id'], PipelineStateService::STAGE_SCRAPE, [
                'status' => PipelineJob::STATUS_RUNNING,
                'metadata' => [
                    'crawlerStatus' => $crawlerStatus ?: 'unknown',
                    'message' => $message,
                    'source' => self::class,
                    'status_read_failures' => $failures,
                ],
            ]);

            $this->reschedule($event, [
                'status_read_failures' => $failures,
                'last_status_read_error' => $message,
            ], 'Crawl4AI status was not readable.');

            return;
        }

        $this->pipelineState->failStage((string) $event['job_id'], PipelineStateService::STAGE_SCRAPE, [
            'errors' => [[
                'message' => $message,
                'status' => $result['status'] ?? null,
                'updatedAt' => now()->toIso8601String(),
            ]],
            'metadata' => ['source' => self::class],
        ]);

        $this->failures->publish($event, $message, [
            'crawlerStatus' => $crawlerStatus ?: 'unknown',
            'status' => $result['status'] ?? null,
            'status_read_failures' => $failures,
        ]);
    }

    private function handleCompleted(array $event, string $datasetPath, array $counts, array $data): void
    {
        $this->pipelineState->completeStage((string) $event['job_id'], PipelineStateService::STAGE_SCRAPE, [
            'dataset_path' => $datasetPath !== '' ? $datasetPath : null,
            'counts' => $counts,
            'metadata' => [
                'message' => $data['message'] ?? 'Crawl completed.',
                'source' => self::class,
            ],
        ]);

        $this->logger->success('pipeline', [
            'job_id' => $event['job_id'],
            'pipeline_stage' => 'scrape_to_convert_trigger',
            'output_dir' => $datasetPath,
        ]);

        if ($datasetPath === '') {
            return;
        }

        $pipelineJob = $this->jobs->findWithTaskByJobId((string) $event['job_id']);
        if (! $pipelineJob?->task_id) {
            return;
        }

        $pipelineJob = $this->jobs->markScrapeMonitorCompleted(
            $pipelineJob,
            $datasetPath,
            now(),
            array_merge($pipelineJob->metadata ?? [], [
                'source' => self::class,
                'crawlerStatus' => 'completed',
            ]),
        );

        $this->outputs->publish($pipelineJob, $datasetPath);
    }

    private function handleFailedStatus(array $event, string $crawlerStatus, string $datasetPath, array $counts, array $data): void
    {
        $message = (string) ($data['message'] ?? "Crawl ended with status {$crawlerStatus}.");

        $this->pipelineState->failStage((string) $event['job_id'], PipelineStateService::STAGE_SCRAPE, [
            'dataset_path' => $datasetPath !== '' ? $datasetPath : null,
            'counts' => $counts,
            'errors' => [[
                'message' => $message,
                'updatedAt' => now()->toIso8601String(),
            ]],
            'metadata' => ['source' => self::class],
        ]);

        $this->failures->publish($event, $message, [
            'crawlerStatus' => $crawlerStatus,
            'dataset_path' => $datasetPath !== '' ? $datasetPath : null,
            'counts' => $counts,
        ]);
    }

    private function handleStillRunning(array $event, string $crawlerStatus, string $datasetPath, array $counts, array $data): void
    {
        $attempt = (int) ($event['metadata']['monitor_attempt'] ?? 0) + 1;
        $maxAttempts = max(1, (int) config('communication.rabbitmq.pipeline_events.scrape_monitor_max_attempts', 240));

        if ($attempt > $maxAttempts) {
            $message = "Crawl monitor exceeded {$maxAttempts} attempts.";
            $this->pipelineState->failStage((string) $event['job_id'], PipelineStateService::STAGE_SCRAPE, [
                'dataset_path' => $datasetPath !== '' ? $datasetPath : null,
                'counts' => $counts,
                'errors' => [[
                    'message' => $message,
                    'updatedAt' => now()->toIso8601String(),
                ]],
                'metadata' => ['source' => self::class],
            ]);
            $this->failures->publish($event, $message, [
                'crawlerStatus' => $crawlerStatus ?: 'unknown',
                'dataset_path' => $datasetPath !== '' ? $datasetPath : null,
                'counts' => $counts,
                'monitor_attempt' => $attempt,
            ]);

            return;
        }

        $this->pipelineState->updateStage((string) $event['job_id'], PipelineStateService::STAGE_SCRAPE, [
            'status' => PipelineJob::STATUS_RUNNING,
            'dataset_path' => $datasetPath !== '' ? $datasetPath : null,
            'counts' => $counts,
            'metadata' => [
                'crawlerStatus' => $crawlerStatus ?: 'unknown',
                'message' => $data['message'] ?? null,
                'source' => self::class,
                'monitor_attempt' => $attempt,
            ],
        ]);

        $this->state->upsertJob($event, PipelineJob::STATUS_RUNNING, [
            'source' => self::class,
            'stage' => 'scrape_monitor_running',
            'monitor_attempt' => $attempt,
            'crawlerStatus' => $crawlerStatus ?: 'unknown',
        ]);

        $this->reschedule($event, [
            'monitor_attempt' => $attempt,
            'crawlerStatus' => $crawlerStatus ?: 'unknown',
        ], 'Crawl is still running.');
    }

    private function reschedule(array $event, array $metadata, string $reason): void
    {
        $this->events->publishDelayed(array_merge($event, [
            'status' => PipelineJob::STATUS_RUNNING,
            'metadata' => array_merge($event['metadata'] ?? [], $metadata, [
                'source' => self::class,
            ]),
        ]), $reason);
    }
}
