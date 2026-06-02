<?php

namespace App\Services\Pipeline;

use App\Models\PipelineJob;
use Illuminate\Support\Str;

class PipelineEvent
{
    public const TASK_STARTED = 'task.started';
    public const PAGE_DISCOVERED = 'page.discovered';
    public const SCRAPE_REQUESTED = 'scrape.requested';
    public const PAGE_SCRAPED = 'page.scraped';
    public const FILE_DISCOVERED = 'file.discovered';
    public const CONVERT_REQUESTED = 'convert.requested';
    public const FILE_CONVERTED = 'file.converted';
    public const INGEST_REQUESTED = 'ingest.requested';
    public const CONTENT_INGESTED = 'content.ingested';
    public const GRAPH_UPDATED = 'graph.updated';
    public const JOB_FAILED = 'job.failed';

    public const REQUIRED_PAYLOAD_FIELDS = [
        'task_id',
        'job_id',
        'parent_job_id',
        'dataset_id',
        'profile_id',
        'job_type',
        'source_url',
        'local_path',
        'content_hash',
        'status',
        'created_at',
        'metadata',
    ];

    public static function normalize(string $eventType, array $payload): array
    {
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $taskId = self::scalar($payload['task_id'] ?? $payload['taskId'] ?? null);
        $sourceUrl = self::scalar($payload['source_url'] ?? $payload['sourceUrl'] ?? null);
        $localPath = self::scalar($payload['local_path'] ?? $payload['localPath'] ?? $payload['converted_path'] ?? null);
        $contentHash = self::scalar($payload['content_hash'] ?? $payload['contentHash'] ?? null);
        $jobType = self::scalar($payload['job_type'] ?? $payload['jobType'] ?? null) ?? self::jobTypeFor($eventType);
        $jobId = self::scalar($payload['job_id'] ?? $payload['jobId'] ?? null)
            ?? self::deterministicJobId($eventType, $taskId, $sourceUrl, $localPath, $contentHash);

        return [
            'event_id' => self::scalar($payload['event_id'] ?? null) ?? (string) Str::uuid(),
            'event_type' => $eventType,
            'task_id' => $taskId,
            'job_id' => $jobId,
            'parent_job_id' => self::scalar($payload['parent_job_id'] ?? $payload['parentJobId'] ?? null),
            'dataset_id' => self::scalar($payload['dataset_id'] ?? $payload['datasetId'] ?? null),
            'profile_id' => self::scalar($payload['profile_id'] ?? $payload['profileId'] ?? null),
            'job_type' => $jobType,
            'source_url' => $sourceUrl,
            'local_path' => $localPath,
            'content_hash' => $contentHash,
            'status' => self::scalar($payload['status'] ?? null) ?? PipelineJob::STATUS_PENDING,
            'created_at' => self::scalar($payload['created_at'] ?? $payload['createdAt'] ?? null) ?? now()->toIso8601String(),
            'metadata' => $metadata,
            'retry_count' => max(0, (int) ($payload['retry_count'] ?? 0)),
            'max_retries' => max(0, (int) ($payload['max_retries'] ?? config('communication.rabbitmq.pipeline_events.max_retries', 3))),
            'schema_version' => self::scalar($payload['schema_version'] ?? null) ?? (string) config('communication.rabbitmq.rag_ingestion.schema_version', '1'),
            'source' => self::scalar($payload['source'] ?? null) ?? 'hawki-rag-laravel',
        ];
    }

    public static function jobTypeFor(string $eventType): ?string
    {
        return match ($eventType) {
            self::PAGE_DISCOVERED,
            self::SCRAPE_REQUESTED,
            self::PAGE_SCRAPED => PipelineJob::TYPE_SCRAPE,
            self::FILE_DISCOVERED,
            self::CONVERT_REQUESTED,
            self::FILE_CONVERTED => PipelineJob::TYPE_CONVERT,
            self::INGEST_REQUESTED,
            self::CONTENT_INGESTED => PipelineJob::TYPE_INGEST,
            self::GRAPH_UPDATED => PipelineJob::TYPE_GRAPH,
            default => null,
        };
    }

    public static function terminalStatus(string $status): bool
    {
        return in_array($status, [
            PipelineJob::STATUS_COMPLETED,
            PipelineJob::STATUS_FAILED,
            PipelineJob::STATUS_SKIPPED,
            PipelineJob::STATUS_PARTIAL,
            PipelineJob::STATUS_CANCELLED,
        ], true);
    }

    private static function deterministicJobId(
        string $eventType,
        ?string $taskId,
        ?string $sourceUrl,
        ?string $localPath,
        ?string $contentHash,
    ): string {
        $prefix = match (self::jobTypeFor($eventType)) {
            PipelineJob::TYPE_SCRAPE => 'scrape',
            PipelineJob::TYPE_CONVERT => 'convert',
            PipelineJob::TYPE_INGEST => 'ingest',
            PipelineJob::TYPE_GRAPH => 'graph',
            default => 'job',
        };

        return $prefix . '_' . substr(hash('sha256', implode('|', [
            $eventType,
            $taskId ?? '',
            $sourceUrl ?? '',
            $localPath ?? '',
            $contentHash ?? '',
        ])), 0, 24);
    }

    private static function scalar(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
