<?php

namespace App\Services\Rag;

use App\Models\JobProcessingState;
use App\Services\Pipeline\PipelineStateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ConvertedDocumentIngestionService
{
    public function __construct(
        private readonly PipelineStateService $pipelineState,
    ) {
    }

    public function claim(array $event, int $retryCount, int $maxRetries): ?JobProcessingState
    {
        $jobId = $this->requiredString($event, 'job_id');
        $now = Carbon::now();

        $state = JobProcessingState::query()->firstOrNew([
            'job_id' => $jobId,
            'stage' => JobProcessingState::STAGE_RAG_INGESTION,
        ]);

        if ($state->exists && $state->status === JobProcessingState::STATUS_COMPLETED) {
            return null;
        }

        $state->fill([
            'source' => (string) ($event['source'] ?? 'unknown'),
            'input_path' => $event['original_path'] ?? null,
            'output_path' => $event['converted_path'] ?? null,
            'input_checksum' => $event['output_checksum_sha256'] ?? $event['input_checksum_sha256'] ?? null,
            'status' => JobProcessingState::STATUS_PROCESSING,
            'retry_count' => $retryCount,
            'max_retries' => $maxRetries,
            'first_received_at' => $state->first_received_at ?: $now,
            'last_received_at' => $now,
            'processing_started_at' => $now,
            'failed_at' => null,
            'error_type' => null,
            'error_message' => null,
            'trace_id' => $event['trace_id'] ?? null,
        ]);
        $state->save();
        $this->updatePipelineIngestCounts($event, [
            'received' => -1,
            'processing' => 1,
        ], [
            'retry_count' => $retryCount,
            'max_retries' => $maxRetries,
            'metadata' => $this->pipelineIngestMetadata($event),
        ]);

        return $state;
    }

    public function ingest(array $event): array
    {
        $this->assertConvertedDocumentEvent($event);
        $markdownPath = $this->resolveMarkdownPath($this->requiredString($event, 'converted_path'));
        $markdown = file_get_contents($markdownPath);

        if (!is_string($markdown) || trim($markdown) === '') {
            throw new InvalidArgumentException("Converted markdown is empty: {$markdownPath}");
        }

        $response = Http::timeout((int) config('communication.rabbitmq.rag_ingestion.bridge_timeout', 3600))
            ->acceptJson()
            ->asJson()
            ->post($this->bridgeUrl() . '/ingest', $this->toBridgePayload($event, $markdown, $markdownPath));

        if ($response->failed()) {
            throw new RuntimeException("Python RAG bridge returned HTTP {$response->status()}: " . Str::limit($response->body(), 1000));
        }

        return $response->json() ?? ['ok' => true];
    }

    public function markCompleted(array $event, int $retryCount, int $maxRetries): void
    {
        JobProcessingState::query()->updateOrCreate(
            [
                'job_id' => $this->requiredString($event, 'job_id'),
                'stage' => JobProcessingState::STAGE_RAG_INGESTION,
            ],
            [
                'source' => (string) ($event['source'] ?? 'unknown'),
                'input_path' => $event['original_path'] ?? null,
                'output_path' => $event['converted_path'] ?? null,
                'input_checksum' => $event['output_checksum_sha256'] ?? $event['input_checksum_sha256'] ?? null,
                'status' => JobProcessingState::STATUS_COMPLETED,
                'retry_count' => $retryCount,
                'max_retries' => $maxRetries,
                'completed_at' => Carbon::now(),
                'failed_at' => null,
                'error_type' => null,
                'error_message' => null,
                'trace_id' => $event['trace_id'] ?? null,
            ],
        );
        $this->updatePipelineIngestCounts($event, [
            'processing' => -1,
            'completed' => 1,
        ], [
            'retry_count' => $retryCount,
            'max_retries' => $maxRetries,
            'metadata' => $this->pipelineIngestMetadata($event),
        ]);
    }

    public function markReceivedForRetry(array $event, int $retryCount, int $maxRetries, Throwable $error): void
    {
        JobProcessingState::query()->updateOrCreate(
            [
                'job_id' => $this->requiredString($event, 'job_id'),
                'stage' => JobProcessingState::STAGE_RAG_INGESTION,
            ],
            [
                'source' => (string) ($event['source'] ?? 'unknown'),
                'input_path' => $event['original_path'] ?? null,
                'output_path' => $event['converted_path'] ?? null,
                'input_checksum' => $event['output_checksum_sha256'] ?? $event['input_checksum_sha256'] ?? null,
                'status' => JobProcessingState::STATUS_RECEIVED,
                'retry_count' => $retryCount,
                'max_retries' => $maxRetries,
                'last_received_at' => Carbon::now(),
                'error_type' => class_basename($error),
                'error_message' => $error->getMessage(),
                'trace_id' => $event['trace_id'] ?? null,
            ],
        );
        $this->updatePipelineIngestCounts($event, [
            'processing' => -1,
            'received' => 1,
        ], [
            'retry_count' => $retryCount,
            'max_retries' => $maxRetries,
            'errors' => [$this->pipelineError($event, $error)],
            'metadata' => $this->pipelineIngestMetadata($event),
        ]);
    }

    public function markFailed(array $event, int $retryCount, int $maxRetries, Throwable $error): void
    {
        JobProcessingState::query()->updateOrCreate(
            [
                'job_id' => (string) ($event['job_id'] ?? Str::uuid()),
                'stage' => JobProcessingState::STAGE_RAG_INGESTION,
            ],
            [
                'source' => (string) ($event['source'] ?? 'unknown'),
                'input_path' => $event['original_path'] ?? null,
                'output_path' => $event['converted_path'] ?? null,
                'input_checksum' => $event['output_checksum_sha256'] ?? $event['input_checksum_sha256'] ?? null,
                'status' => JobProcessingState::STATUS_FAILED,
                'retry_count' => $retryCount,
                'max_retries' => $maxRetries,
                'failed_at' => Carbon::now(),
                'error_type' => class_basename($error),
                'error_message' => $error->getMessage(),
                'trace_id' => $event['trace_id'] ?? null,
            ],
        );
        $this->updatePipelineIngestCounts($event, [
            'processing' => -1,
            'failed' => 1,
        ], [
            'retry_count' => $retryCount,
            'max_retries' => $maxRetries,
            'errors' => [$this->pipelineError($event, $error)],
            'metadata' => $this->pipelineIngestMetadata($event),
        ]);
    }

    public function failedEvent(array $event, int $retryCount, int $maxRetries, Throwable $error): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'job_id' => (string) ($event['job_id'] ?? Str::uuid()),
            'parent_event_id' => $event['event_id'] ?? null,
            'schema_version' => (string) config('communication.rabbitmq.rag_ingestion.schema_version', '1'),
            'event_type' => 'pipeline.failed',
            'failed_stage' => JobProcessingState::STAGE_RAG_INGESTION,
            'source' => (string) config('communication.rabbitmq.rag_ingestion.service_name', 'hawki-rag'),
            'error_type' => class_basename($error),
            'error_message' => $error->getMessage(),
            'retry_count' => $retryCount,
            'max_retries' => $maxRetries,
            'original_event_type' => (string) ($event['event_type'] ?? 'convert.document.completed'),
            'original_event_payload' => $event,
            'failed_at' => Carbon::now()->toIso8601String(),
            'trace_id' => $event['trace_id'] ?? null,
        ];
    }

    public function isPermanent(Throwable $error): bool
    {
        return $error instanceof InvalidArgumentException;
    }

    private function assertConvertedDocumentEvent(array $event): void
    {
        foreach (['event_id', 'job_id', 'event_type', 'original_url', 'original_path', 'converted_path', 'output_format', 'converter_name'] as $key) {
            $this->requiredString($event, $key);
        }

        if ($event['event_type'] !== 'convert.document.completed') {
            throw new InvalidArgumentException('Unsupported event_type: ' . (string) $event['event_type']);
        }

        if ($event['output_format'] !== 'markdown') {
            throw new InvalidArgumentException('Unsupported output_format: ' . (string) $event['output_format']);
        }
    }

    private function updatePipelineIngestCounts(array $event, array $deltas, array $attributes = []): void
    {
        $pipelineJobId = $this->pipelineJobId($event);
        if ($pipelineJobId === '') {
            return;
        }

        $stage = $this->pipelineState->incrementStageCounts(
            $pipelineJobId,
            PipelineStateService::STAGE_INGEST,
            $deltas,
            array_merge($attributes, [
                'status' => 'processing',
                'dataset_path' => $this->datasetPathFromEvent($event),
            ])
        );

        $counts = is_array($stage?->counts) ? $stage->counts : [];
        $this->pipelineState->updateStage($pipelineJobId, PipelineStateService::STAGE_INGEST, [
            'status' => $this->pipelineIngestStatus($counts),
            'dataset_path' => $this->datasetPathFromEvent($event),
            'counts' => $counts,
            'retry_count' => $attributes['retry_count'] ?? null,
            'max_retries' => $attributes['max_retries'] ?? null,
            'errors' => $attributes['errors'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
        ]);
    }

    private function pipelineIngestStatus(array $counts): string
    {
        $received = (int) ($counts['received'] ?? 0);
        $processing = (int) ($counts['processing'] ?? 0);
        $completed = (int) ($counts['completed'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);
        $total = (int) ($counts['total'] ?? 0);

        if ($processing > 0) {
            return 'processing';
        }
        if ($received > 0) {
            return 'received';
        }
        if ($failed > 0 && $completed > 0) {
            return 'partial';
        }
        if ($failed > 0) {
            return 'failed';
        }
        if ($completed > 0 && ($total === 0 || $completed >= $total)) {
            return 'completed';
        }

        return 'received';
    }

    private function pipelineJobId(array $event): string
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        return (string) ($event['pipeline_job_id'] ?? $payload['pipeline_job_id'] ?? '');
    }

    private function datasetPathFromEvent(array $event): ?string
    {
        $path = (string) ($event['original_path'] ?? $event['converted_path'] ?? '');
        if ($path === '') {
            return null;
        }

        $root = realpath((string) config('communication.rabbitmq.rag_ingestion.shared_storage_root', '/app/shared'));
        $resolved = realpath($path) ?: $path;
        if (!$root || !Str::startsWith($resolved, $root . DIRECTORY_SEPARATOR)) {
            return dirname($resolved);
        }

        $relative = ltrim(substr($resolved, strlen($root)), DIRECTORY_SEPARATOR);
        $topLevel = strtok($relative, DIRECTORY_SEPARATOR);
        return $topLevel ? $root . DIRECTORY_SEPARATOR . $topLevel : $root;
    }

    private function pipelineIngestMetadata(array $event): array
    {
        return [
            'latestDocumentJobId' => (string) ($event['job_id'] ?? ''),
            'latestEventId' => (string) ($event['event_id'] ?? ''),
            'latestConvertedPath' => $event['converted_path'] ?? null,
        ];
    }

    private function pipelineError(array $event, Throwable $error): array
    {
        return [
            'jobId' => (string) ($event['job_id'] ?? ''),
            'eventId' => (string) ($event['event_id'] ?? ''),
            'errorType' => class_basename($error),
            'message' => $error->getMessage(),
            'updatedAt' => Carbon::now()->toIso8601String(),
        ];
    }

    private function toBridgePayload(array $event, string $markdown, string $markdownPath): array
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $payload += [
            'source_format' => 'markdown',
            'source_type' => 'convert.document.completed',
            'file_path' => $markdownPath,
            'source_url' => $event['original_url'],
            'page_url' => $event['original_url'],
            'original_path' => $event['original_path'],
            'original_relative_path' => $event['original_relative_path'] ?? null,
            'converted_path' => $event['converted_path'],
            'converted_relative_path' => $event['converted_relative_path'] ?? null,
            'converter_name' => $event['converter_name'],
            'converter_version' => $event['converter_version'] ?? null,
            'input_checksum_sha256' => $event['input_checksum_sha256'] ?? null,
            'output_checksum_sha256' => $event['output_checksum_sha256'] ?? null,
            'trace_id' => $event['trace_id'] ?? null,
            'job_id' => (string) $event['job_id'],
            'event_id' => (string) $event['event_id'],
            'title' => $this->titleFromEvent($event, $markdownPath),
        ];

        return [
            'docs' => [[
                'id' => (string) $event['job_id'],
                'text' => $markdown,
                'payload' => $payload,
            ]],
            'provider' => (string) ($payload['provider'] ?? config('communication.rabbitmq.rag_ingestion.provider', 'ollama')),
            'embedding_model' => $payload['embedding_model'] ?? null,
            'collection' => $payload['collection'] ?? null,
            'neo4j_database' => $payload['neo4j_database'] ?? null,
            'distance' => (string) ($payload['distance'] ?? env('QDRANT_DISTANCE', 'Cosine')),
            'chunk_chars' => (int) ($payload['chunk_chars'] ?? env('CHUNK_SIZE', 1200)),
            'chunk_overlap' => (int) ($payload['chunk_overlap'] ?? env('CHUNK_OVERLAP_SIZE', 250)),
            'batch_size' => (int) ($payload['batch_size'] ?? env('INGEST_BATCH_SIZE', 64)),
            'graph' => $this->boolValue($payload['graph'] ?? config('communication.rabbitmq.rag_ingestion.graph', false)),
            'graph_engine' => (string) ($payload['graph_engine'] ?? env('GRAPH_ENGINE', 'raganything')),
            'graph_only' => $this->boolValue($payload['graph_only'] ?? false),
            'dry_run' => $this->boolValue($payload['dry_run'] ?? false),
            'dry_include_graph' => $this->boolValue($payload['dry_include_graph'] ?? false),
        ];
    }

    private function resolveMarkdownPath(string $convertedPath): string
    {
        $root = rtrim((string) config('communication.rabbitmq.rag_ingestion.shared_storage_root', '/app/shared'), DIRECTORY_SEPARATOR);
        $candidate = Str::startsWith($convertedPath, ['/','\\']) ? $convertedPath : $root . DIRECTORY_SEPARATOR . ltrim($convertedPath, DIRECTORY_SEPARATOR);
        $resolved = realpath($candidate);
        $resolvedRoot = realpath($root);

        if ($resolved === false || !is_file($resolved)) {
            throw new InvalidArgumentException("Converted file missing: {$candidate}");
        }

        if ($resolvedRoot === false || !Str::startsWith($resolved, $resolvedRoot . DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException("Converted path is outside shared storage: {$resolved}");
        }

        return $resolved;
    }

    private function titleFromEvent(array $event, string $markdownPath): string
    {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        if (!empty($payload['title'])) {
            return (string) $payload['title'];
        }

        foreach (['original_relative_path', 'converted_relative_path'] as $key) {
            if (!empty($event[$key])) {
                return pathinfo((string) $event[$key], PATHINFO_FILENAME);
            }
        }

        return pathinfo($markdownPath, PATHINFO_FILENAME) ?: (string) $event['job_id'];
    }

    private function bridgeUrl(): string
    {
        return rtrim((string) config('config.hawki_rag_bridge_url', 'http://hawki_rag_bridge:8000'), '/');
    }

    private function requiredString(array $event, string $key): string
    {
        $value = $event[$key] ?? null;
        if (!is_scalar($value) || trim((string) $value) === '') {
            throw new InvalidArgumentException("Missing required event field: {$key}");
        }

        return (string) $value;
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
