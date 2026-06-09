<?php

declare(strict_types=1);

namespace App\Services\Pipeline\EventHandlers;

use App\Models\JobProcessingState;
use App\Models\PipelineJob;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventBus;
use App\Services\Pipeline\Events\PipelineEventStateService;
use App\Services\Pipeline\Exceptions\PipelineEventHandlerException;
use App\Services\Pipeline\Repositories\PipelineIngestionRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
class IngestionEventHandler implements PipelineEventHandler
{
    public function __construct(
        private readonly PipelineEventBus $events,
        private readonly PipelineEventStateService $state,
        private readonly PipelineIngestionRepository $ingestion,
        private readonly IngestionContentResolver $content,
        private readonly IngestionBridgeClient $bridge,
        private readonly ConfigRepository $config,
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
            $this->ingestPath($event, $path);
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
            $ingestEvent = $this->ingestEventForPath($event, $path);
            $this->state->upsertJob($ingestEvent, $retryable ? PipelineJob::STATUS_PENDING : PipelineJob::STATUS_FAILED, [
                'retry_count' => $retryCount,
                'max_retries' => $maxRetries,
                'retry_scheduled' => $retryable,
                'error_type' => class_basename($error),
                'error_message' => $error->getMessage(),
            ]);

            if ($retryable) {
                $this->markProcessingState($ingestEvent, JobProcessingState::STATUS_RECEIVED);
            } else {
                $this->markProcessingStateFailed($ingestEvent, $error, $retryCount, $maxRetries);
            }
        }
    }

    private function ingestPath(array $sourceEvent, string $path): void
    {
        $event = $this->ingestEventForPath($sourceEvent, $path);
        $this->state->upsertJob($event, PipelineJob::STATUS_RUNNING, [
            'source_event_type' => $sourceEvent['event_type'],
        ]);
        $this->markProcessingState($event, JobProcessingState::STATUS_PROCESSING);

        $text = (string) file_get_contents($path);
        if (trim($text) === '') {
            throw PipelineEventHandlerException::ingestContentIsEmpty($path);
        }

        $bridgeResponse = $this->bridge->ingest($event, $text, $path);

        $this->state->upsertJob($event, PipelineJob::STATUS_COMPLETED, [
            'bridge_response' => $bridgeResponse,
        ]);
        $this->markProcessingState($event, JobProcessingState::STATUS_COMPLETED);
        $this->recordDocument($event, $path, $bridgeResponse);

        $this->events->publish(PipelineEvent::CONTENT_INGESTED, array_merge($event, [
            'status' => PipelineJob::STATUS_COMPLETED,
            'metadata' => array_merge($event['metadata'], [
                'bridge_response' => $bridgeResponse,
            ]),
        ]));

    }

    private function ingestEventForPath(array $event, string $path): array
    {
        $path = $this->content->resolvePath($path) ?? $path;
        $hash = is_file($path) ? (hash_file('sha256', $path) ?: hash('sha256', $path)) : hash('sha256', $path);
        $jobId = 'ingest_'.substr(hash('sha256', ($event['task_id'] ?? '').'|'.($event['job_id'] ?? '').'|'.$path), 0, 24);
        $datasetId = (string) ($event['dataset_id'] ?: 'default');

        return PipelineEvent::normalize(PipelineEvent::CONTENT_INGESTED, [
            'task_id' => $event['task_id'],
            'job_id' => $jobId,
            'parent_job_id' => $event['job_id'],
            'dataset_id' => $datasetId,
            'job_type' => PipelineJob::TYPE_INGEST,
            'source_url' => $event['source_url'],
            'local_path' => $path,
            'content_hash' => $hash,
            'status' => PipelineJob::STATUS_RUNNING,
            'metadata' => array_merge($event['metadata'] ?? [], [
                'source_event_type' => $event['event_type'],
                'source_job_id' => $event['job_id'],
            ]),
        ]);
    }

    private function markProcessingState(array $event, string $status): void
    {
        $this->ingestion->upsertProcessingState(
            $event,
            $status,
            (int) $this->config->get('communication.rabbitmq.pipeline_events.max_retries', 3),
        );
    }

    private function markProcessingStateFailed(array $event, \Throwable $error, int $retryCount, int $maxRetries): void
    {
        $this->ingestion->upsertFailedProcessingState($event, $error, $retryCount, $maxRetries);
    }

    private function recordDocument(array $event, string $path, array $bridgeResponse): void
    {
        $targets = $this->bridge->targets((string) ($event['dataset_id'] ?: 'default'));
        $checksum = is_file($path) ? (hash_file('sha256', $path) ?: $event['content_hash']) : $event['content_hash'];

        $this->ingestion->upsertIngestedDocument(
            $event,
            $targets,
            $path,
            $checksum,
            is_file($path) ? (filesize($path) ?: null) : null,
            $bridgeResponse,
        );
    }
}
