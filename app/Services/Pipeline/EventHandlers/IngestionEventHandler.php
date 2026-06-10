<?php

declare(strict_types=1);

namespace App\Services\Pipeline\EventHandlers;

use App\Models\JobProcessingState;
use App\Models\PipelineJob;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventStateService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
class IngestionEventHandler implements PipelineEventHandler
{
    public function __construct(
        private readonly PipelineEventStateService $state,
        private readonly IngestionContentResolver $content,
        private readonly IngestionEventFactory $eventsForIngestion,
        private readonly IngestionPathProcessor $paths,
        private readonly IngestionProcessingStateWriter $processingStates,
    ) {}

    public function eventTypes(): array
    {
        return [
            PipelineEvent::PAGE_SCRAPED,
            PipelineEvent::FILE_CONVERTED,
        ];
    }

    public function handle(array $event): void
    {
        $event = PipelineEvent::normalize((string) $event['event_type'], $event);
        $paths = $this->content->contentPaths($event);

        if ($paths === []) {
            $this->state->upsertJob($this->ingestEventForPath($event, $event['local_path'] ?: $event['source_url'] ?: 'skipped'), PipelineJob::STATUS_SKIPPED, [
                'reason' => 'No ingestable content path was found.',
            ]);

            return;
        }

        foreach ($paths as $path) {
            $this->paths->ingest($event, $path);
        }
    }

    public function failed(array $event, \Throwable $error, int $retryCount, int $maxRetries): void
    {
        $event = PipelineEvent::normalize((string) ($event['event_type'] ?? PipelineEvent::CONTENT_INGESTED), $event);
        $retryable = $retryCount < $maxRetries;
        $paths = $this->content->contentPaths($event);
        if ($paths === []) {
            $paths = [$event['local_path'] ?: $event['source_url'] ?: 'failed-ingestion-event'];
        }

        foreach ($paths as $path) {
            $ingestEvent = $this->eventsForIngestion->forPath($event, $path);
            $this->state->upsertJob($ingestEvent, $retryable ? PipelineJob::STATUS_PENDING : PipelineJob::STATUS_FAILED, [
                'retry_count' => $retryCount,
                'max_retries' => $maxRetries,
                'retry_scheduled' => $retryable,
                'error_type' => class_basename($error),
                'error_message' => $error->getMessage(),
            ]);

            if ($retryable) {
                $this->processingStates->mark($ingestEvent, JobProcessingState::STATUS_RECEIVED);
            } else {
                $this->processingStates->failed($ingestEvent, $error, $retryCount, $maxRetries);
            }
        }
    }
}
