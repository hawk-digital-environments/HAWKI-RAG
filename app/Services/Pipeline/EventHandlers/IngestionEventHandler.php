<?php

namespace App\Services\Pipeline\EventHandlers;

use App\Models\Document;
use App\Models\JobProcessingState;
use App\Models\PipelineJob;
use App\Services\Datasets\DatasetService;
use App\Services\Pipeline\PipelineEvent;
use App\Services\Pipeline\PipelineEventBus;
use App\Services\Pipeline\PipelineEventStateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class IngestionEventHandler implements PipelineEventHandler
{
    public function __construct(
        private readonly PipelineEventBus $events,
        private readonly PipelineEventStateService $state,
        private readonly DatasetService $datasets,
    ) {
    }

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
        $paths = $this->contentPaths($event);

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

    public function failed(array $event, Throwable $error, int $retryCount, int $maxRetries): void
    {
        $event = PipelineEvent::normalize((string) ($event['event_type'] ?? PipelineEvent::CONTENT_INGESTED), $event);
        $retryable = $retryCount < $maxRetries;
        $paths = $this->contentPaths($event);
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
            throw new \InvalidArgumentException("Ingest content is empty: {$path}");
        }

        $response = Http::timeout((int) config('communication.rabbitmq.pipeline_ingestion.bridge_timeout', 3600))
            ->acceptJson()
            ->asJson()
            ->post($this->bridgeUrl() . '/ingest', $this->bridgePayload($event, $text, $path));

        if ($response->failed()) {
            throw new \RuntimeException("Python RAG bridge returned HTTP {$response->status()}: " . Str::limit($response->body(), 1000));
        }

        $this->state->upsertJob($event, PipelineJob::STATUS_COMPLETED, [
            'bridge_response' => $response->json() ?? ['ok' => true],
        ]);
        $this->markProcessingState($event, JobProcessingState::STATUS_COMPLETED);
        $this->recordDocument($event, $path, $response->json() ?? ['ok' => true]);

        $this->events->publish(PipelineEvent::CONTENT_INGESTED, array_merge($event, [
            'status' => PipelineJob::STATUS_COMPLETED,
            'metadata' => array_merge($event['metadata'], [
                'bridge_response' => $response->json() ?? ['ok' => true],
            ]),
        ]));

    }

    private function ingestEventForPath(array $event, string $path): array
    {
        $path = $this->resolvePath($path) ?? $path;
        $hash = is_file($path) ? (hash_file('sha256', $path) ?: hash('sha256', $path)) : hash('sha256', $path);
        $jobId = 'ingest_' . substr(hash('sha256', ($event['task_id'] ?? '') . '|' . ($event['job_id'] ?? '') . '|' . $path), 0, 24);
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

    private function contentPaths(array $event): array
    {
        $path = $this->resolvePath((string) ($event['local_path'] ?? ''));
        if ($path && is_file($path) && $this->isTextLike($path)) {
            return [$path];
        }

        if ($path && is_dir($path)) {
            $paths = [];
            foreach (File::allFiles($path) as $file) {
                if ($this->isTextLike($file->getPathname())) {
                    $paths[] = $file->getPathname();
                }
            }

            return $paths;
        }

        return [];
    }

    private function isTextLike(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['md', 'txt', 'html'], true);
    }

    private function resolvePath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        if (Str::startsWith($path, ['/','\\'])) {
            return realpath($path) ?: $path;
        }

        $candidate = rtrim((string) config('communication.rabbitmq.pipeline_ingestion.shared_storage_root', '/app/shared'), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . ltrim($path, DIRECTORY_SEPARATOR);

        return realpath($candidate) ?: $candidate;
    }

    private function markProcessingState(array $event, string $status): void
    {
        $now = Carbon::now();
        JobProcessingState::query()->updateOrCreate(
            [
                'job_id' => (string) $event['job_id'],
                'stage' => JobProcessingState::STAGE_RAG_INGESTION,
            ],
            [
                'source' => (string) ($event['source'] ?? 'hawki-rag-laravel'),
                'input_path' => $event['source_url'],
                'output_path' => $event['local_path'],
                'input_checksum' => $event['content_hash'],
                'status' => $status,
                'retry_count' => (int) ($event['retry_count'] ?? 0),
                'max_retries' => (int) ($event['max_retries'] ?? config('communication.rabbitmq.pipeline_events.max_retries', 3)),
                'first_received_at' => $now,
                'last_received_at' => $now,
                'processing_started_at' => $status === JobProcessingState::STATUS_PROCESSING ? $now : null,
                'completed_at' => $status === JobProcessingState::STATUS_COMPLETED ? $now : null,
                'failed_at' => $status === JobProcessingState::STATUS_FAILED ? $now : null,
                'trace_id' => $event['event_id'],
            ],
        );
    }

    private function markProcessingStateFailed(array $event, Throwable $error, int $retryCount, int $maxRetries): void
    {
        JobProcessingState::query()->updateOrCreate(
            [
                'job_id' => (string) ($event['job_id'] ?? Str::uuid()),
                'stage' => JobProcessingState::STAGE_RAG_INGESTION,
            ],
            [
                'source' => (string) ($event['source'] ?? 'hawki-rag-laravel'),
                'input_path' => $event['source_url'] ?? null,
                'output_path' => $event['local_path'] ?? null,
                'input_checksum' => $event['content_hash'] ?? null,
                'status' => JobProcessingState::STATUS_FAILED,
                'retry_count' => $retryCount,
                'max_retries' => $maxRetries,
                'failed_at' => Carbon::now(),
                'error_type' => class_basename($error),
                'error_message' => $error->getMessage(),
                'trace_id' => $event['event_id'] ?? null,
            ],
        );
    }

    private function bridgePayload(array $event, string $text, string $path): array
    {
        $targets = $this->datasets->bridgeTargets((string) ($event['dataset_id'] ?: 'default'));
        $payload = [
            'source_format' => 'markdown',
            'source_type' => $event['metadata']['source_event_type'] ?? $event['event_type'],
            'dataset_id' => $targets['dataset_id'],
            'qdrant_collection' => $targets['qdrant_collection'],
            'neo4j_namespace' => $targets['neo4j_namespace'],
            'file_path' => $path,
            'source_url' => $event['source_url'],
            'page_url' => $event['source_url'],
            'original_path' => $event['metadata']['original_path'] ?? $path,
            'converted_path' => $path,
            'input_checksum_sha256' => $event['content_hash'],
            'output_checksum_sha256' => $event['content_hash'],
            'trace_id' => $event['event_id'],
            'job_id' => $event['job_id'],
            'event_id' => $event['event_id'],
            'task_id' => $event['task_id'],
            'title' => pathinfo($path, PATHINFO_FILENAME),
        ];

        return [
            'docs' => [[
                'id' => (string) $event['job_id'],
                'text' => $text,
                'payload' => $payload,
            ]],
            'provider' => (string) config('communication.rabbitmq.pipeline_ingestion.provider', 'ollama'),
            'embedding_model' => null,
            'collection' => $targets['qdrant_collection'],
            'neo4j_database' => null,
            'neo4j_namespace' => $targets['neo4j_namespace'],
            'distance' => (string) env('QDRANT_DISTANCE', 'Cosine'),
            'chunk_chars' => (int) env('CHUNK_SIZE', 1200),
            'chunk_overlap' => (int) env('CHUNK_OVERLAP_SIZE', 250),
            'batch_size' => (int) env('INGEST_BATCH_SIZE', 64),
            'graph' => filter_var($event['metadata']['graph'] ?? config('communication.rabbitmq.pipeline_ingestion.graph', false), FILTER_VALIDATE_BOOLEAN),
            'graph_engine' => (string) env('GRAPH_ENGINE', 'raganything'),
            'graph_only' => false,
            'dry_run' => false,
            'dry_include_graph' => false,
        ];
    }

    private function bridgeUrl(): string
    {
        return rtrim((string) env('HAWKI_RAG_BRIDGE_URL', 'http://hawki_rag_bridge:8000'), '/');
    }

    private function recordDocument(array $event, string $path, array $bridgeResponse): Document
    {
        $targets = $this->datasets->bridgeTargets((string) ($event['dataset_id'] ?: 'default'));
        $checksum = is_file($path) ? (hash_file('sha256', $path) ?: $event['content_hash']) : $event['content_hash'];

        return Document::query()->updateOrCreate(
            [
                'collection' => $targets['qdrant_collection'],
                'checksum_sha256' => $checksum,
            ],
            [
                'external_id' => (string) $event['job_id'],
                'dataset_id' => $targets['dataset_id'],
                'source_type' => Document::SOURCE_SCRAPE,
                'source_url' => $event['source_url'],
                'original_filename' => basename($path),
                'storage_path' => $path,
                'mime_type' => 'text/markdown',
                'file_size' => is_file($path) ? (filesize($path) ?: null) : null,
                'title' => pathinfo($path, PATHINFO_FILENAME),
                'metadata_json' => [
                    'task_id' => $event['task_id'],
                    'job_id' => $event['job_id'],
                    'event_id' => $event['event_id'],
                    'qdrant_collection' => $targets['qdrant_collection'],
                    'neo4j_namespace' => $targets['neo4j_namespace'],
                    'bridge_response' => $bridgeResponse,
                ],
                'status' => Document::STATUS_COMPLETED,
            ],
        );
    }
}
