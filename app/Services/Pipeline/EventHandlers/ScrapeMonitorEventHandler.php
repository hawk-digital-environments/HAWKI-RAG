<?php

namespace App\Services\Pipeline\EventHandlers;

use App\Models\PipelineJob;
use App\Services\Pipeline\PipelineEvent;
use App\Services\Pipeline\PipelineEventBus;
use App\Services\Pipeline\PipelineEventStateService;
use App\Services\Pipeline\PipelineLogger;
use App\Services\Pipeline\PipelineStateService;
use App\Services\ScrapeService\ScrapeService;
use RuntimeException;
use Throwable;

class ScrapeMonitorEventHandler implements PipelineEventHandler
{
    public function __construct(
        private readonly PipelineEventBus $events,
        private readonly PipelineEventStateService $state,
        private readonly PipelineStateService $pipelineState,
        private readonly ScrapeService $scrapeService,
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
        $event = PipelineEvent::normalize(PipelineEvent::SCRAPE_MONITOR_REQUESTED, $event);

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

        if (!($result['success'] ?? false)) {
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

    public function failed(array $event, Throwable $error, int $retryCount, int $maxRetries): void
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

        $this->failPipelineJob($event, $message, [
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

        PipelineLogger::success('pipeline', [
            'job_id' => $event['job_id'],
            'pipeline_stage' => 'scrape_to_convert_trigger',
            'output_dir' => $datasetPath,
        ]);

        if ($datasetPath === '') {
            return;
        }

        $pipelineJob = PipelineJob::query()->where('job_id', $event['job_id'])->first();
        if (!$pipelineJob?->task_id) {
            return;
        }

        $pipelineJob->forceFill([
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'status' => PipelineJob::STATUS_COMPLETED,
            'local_path' => $datasetPath,
            'completed_at' => now(),
            'finished_at' => now(),
            'metadata' => array_merge($pipelineJob->metadata ?? [], [
                'source' => self::class,
                'crawlerStatus' => 'completed',
            ]),
        ])->save();

        $this->publishScrapeCompletedEvents($pipelineJob->refresh(), $datasetPath);
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

        $this->failPipelineJob($event, $message, [
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
            $this->failPipelineJob($event, $message, [
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

    private function publishScrapeCompletedEvents(PipelineJob $job, string $datasetPath): void
    {
        $task = $job->task;
        $basePayload = [
            'task_id' => $job->task_id,
            'job_id' => $job->job_id,
            'parent_job_id' => $job->parent_job_id,
            'dataset_id' => $task?->dataset_id ?? ($job->metadata['dataset_id'] ?? null),
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => $job->source_url,
            'local_path' => $datasetPath,
            'content_hash' => $job->content_hash,
            'status' => PipelineJob::STATUS_COMPLETED,
            'metadata' => array_merge($job->metadata ?? [], [
                'dataset_path' => $datasetPath,
                'source' => self::class,
            ]),
        ];

        $scrapedEvent = PipelineEvent::normalize(PipelineEvent::PAGE_SCRAPED, $basePayload);
        $this->state->upsertJob($scrapedEvent, PipelineJob::STATUS_COMPLETED, [
            'dataset_path' => $datasetPath,
        ]);
        $this->events->publish(PipelineEvent::PAGE_SCRAPED, $scrapedEvent);

        foreach ($this->supportedFiles($datasetPath) as $path) {
            $hash = @hash_file('sha256', $path) ?: hash('sha256', $path);
            $this->events->publish(PipelineEvent::FILE_DISCOVERED, [
                'task_id' => $job->task_id,
                'job_id' => 'convert_' . substr(hash('sha256', $job->task_id . '|' . $path), 0, 24),
                'parent_job_id' => $job->job_id,
                'dataset_id' => $task?->dataset_id ?? ($job->metadata['dataset_id'] ?? null),
                'job_type' => PipelineJob::TYPE_CONVERT,
                'source_url' => $job->source_url,
                'local_path' => $path,
                'content_hash' => $hash,
                'status' => PipelineJob::STATUS_PENDING,
                'metadata' => [
                    'source' => self::class,
                    'dataset_path' => $datasetPath,
                ],
            ]);
        }
    }

    private function failPipelineJob(array $event, string $message, array $metadata = [], ?Throwable $exception = null): void
    {
        $pipelineJob = PipelineJob::query()->where('job_id', $event['job_id'])->first();
        if (!$pipelineJob?->task_id) {
            return;
        }

        $task = $pipelineJob->task;
        $original = PipelineEvent::normalize(PipelineEvent::SCRAPE_REQUESTED, [
            'task_id' => $pipelineJob->task_id,
            'job_id' => $pipelineJob->job_id,
            'parent_job_id' => $pipelineJob->parent_job_id,
            'dataset_id' => $task?->dataset_id ?? ($pipelineJob->metadata['dataset_id'] ?? $event['dataset_id'] ?? null),
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => $pipelineJob->source_url ?: $event['source_url'],
            'local_path' => $pipelineJob->local_path ?: $event['local_path'],
            'content_hash' => $pipelineJob->content_hash ?: $event['content_hash'],
            'status' => PipelineJob::STATUS_FAILED,
            'metadata' => array_merge($pipelineJob->metadata ?? [], $metadata, [
                'source' => self::class,
                'error_message' => $message,
            ]),
        ]);

        $failedEvent = PipelineEvent::normalize(PipelineEvent::JOB_FAILED, $original);
        $this->state->upsertJob($failedEvent, PipelineJob::STATUS_FAILED);
        $this->events->publishFailed($original, $exception ?? new RuntimeException($message));
    }

    private function supportedFiles(string $datasetPath): array
    {
        $resolved = realpath($datasetPath);
        if ($resolved === false || !is_dir($resolved)) {
            return [];
        }

        $extensions = array_map('strtolower', config('file_converter.supported_extensions', ['pdf', 'doc', 'docx']));
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
