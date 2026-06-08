<?php

namespace App\Services\Pipeline;

use App\Models\PipelineJob;
use Illuminate\Support\Str;

class PipelineEvent
{
    public const SCRAPE_REQUESTED = 'scrape.requested';
    public const SCRAPE_MONITOR_REQUESTED = 'scrape.monitor.requested';
    public const PAGE_SCRAPED = 'page.scraped';
    public const FILE_DISCOVERED = 'file.discovered';
    public const FILE_CONVERTED = 'file.converted';
    public const CONTENT_INGESTED = 'content.ingested';
    public const JOB_FAILED = 'job.failed';

    public const REQUIRED_PAYLOAD_FIELDS = [
        'task_id',
        'job_id',
        'parent_job_id',
        'dataset_id',
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
            'job_type' => $jobType,
            'source_url' => $sourceUrl,
            'local_path' => $localPath,
            'content_hash' => $contentHash,
            'status' => self::scalar($payload['status'] ?? null) ?? PipelineJob::STATUS_QUEUED,
            'created_at' => self::scalar($payload['created_at'] ?? $payload['createdAt'] ?? null) ?? now()->toIso8601String(),
            'metadata' => $metadata,
            'retry_count' => max(0, (int) ($payload['retry_count'] ?? 0)),
            'max_retries' => max(0, (int) ($payload['max_retries'] ?? config('communication.rabbitmq.pipeline_events.max_retries', 3))),
            'schema_version' => self::scalar($payload['schema_version'] ?? null) ?? (string) config('communication.rabbitmq.pipeline_events.schema_version', '1'),
            'source' => self::scalar($payload['source'] ?? null) ?? 'hawki-rag-laravel',
        ];
    }

    public static function jobTypeFor(string $eventType): ?string
    {
        return match ($eventType) {
            self::SCRAPE_REQUESTED,
            self::SCRAPE_MONITOR_REQUESTED,
            self::PAGE_SCRAPED => PipelineJob::TYPE_SCRAPE,
            self::FILE_DISCOVERED,
            self::FILE_CONVERTED => PipelineJob::TYPE_CONVERT,
            self::CONTENT_INGESTED => PipelineJob::TYPE_INGEST,
            default => null,
        };
    }

    public static function terminalStatus(string $status): bool
    {
        return in_array($status, [
            PipelineJob::STATUS_COMPLETED,
            PipelineJob::STATUS_FAILED,
            PipelineJob::STATUS_SKIPPED,
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
