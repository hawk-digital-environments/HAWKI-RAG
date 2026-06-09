<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\Document;
use App\Models\JobProcessingState;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineIngestionRepository
{
    public function __construct(private ClockInterface $clock = new Clock)
    {
    }

    public function upsertProcessingState(array $event, string $status, int $defaultMaxRetries): JobProcessingState
    {
        $now = $this->now();

        return JobProcessingState::query()->updateOrCreate(
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
                'max_retries' => (int) ($event['max_retries'] ?? $defaultMaxRetries),
                'first_received_at' => $now,
                'last_received_at' => $now,
                'processing_started_at' => $status === JobProcessingState::STATUS_PROCESSING ? $now : null,
                'completed_at' => $status === JobProcessingState::STATUS_COMPLETED ? $now : null,
                'failed_at' => $status === JobProcessingState::STATUS_FAILED ? $now : null,
                'trace_id' => $event['event_id'],
            ],
        );
    }

    public function upsertFailedProcessingState(
        array $event,
        \Throwable $error,
        int $retryCount,
        int $maxRetries,
    ): JobProcessingState {
        return JobProcessingState::query()->updateOrCreate(
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
                'failed_at' => $this->now(),
                'error_type' => class_basename($error),
                'error_message' => $error->getMessage(),
                'trace_id' => $event['event_id'] ?? null,
            ],
        );
    }

    /**
     * @param array{dataset_id:string,qdrant_collection:string,neo4j_namespace:string} $targets
     * @param array<string, mixed> $bridgeResponse
     */
    public function upsertIngestedDocument(
        array $event,
        array $targets,
        string $path,
        string $checksum,
        ?int $fileSize,
        array $bridgeResponse,
    ): Document {
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
                'file_size' => $fileSize,
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

    private function now(): Carbon
    {
        return Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
