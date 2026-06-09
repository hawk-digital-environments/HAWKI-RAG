<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

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
        return self::normalizer()->normalize($eventType, $payload);
    }

    public static function jobTypeFor(string $eventType): ?string
    {
        return self::types()->jobTypeFor($eventType);
    }

    public static function terminalStatus(string $status): bool
    {
        return self::types()->terminalStatus($status);
    }

    private static function normalizer(): PipelineEventNormalizer
    {
        return new PipelineEventNormalizer(self::types());
    }

    private static function types(): PipelineEventTypeRegistry
    {
        return new PipelineEventTypeRegistry();
    }
}
