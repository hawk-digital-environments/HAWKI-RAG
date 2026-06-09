<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Events;

use App\Services\Pipeline\Values\PipelineEventType;

class PipelineEvent
{
    public const SCRAPE_REQUESTED = PipelineEventType::ScrapeRequested->value;

    public const SCRAPE_MONITOR_REQUESTED = PipelineEventType::ScrapeMonitorRequested->value;

    public const PAGE_SCRAPED = PipelineEventType::PageScraped->value;

    public const FILE_DISCOVERED = PipelineEventType::FileDiscovered->value;

    public const FILE_CONVERTED = PipelineEventType::FileConverted->value;

    public const CONTENT_INGESTED = PipelineEventType::ContentIngested->value;

    public const JOB_FAILED = PipelineEventType::JobFailed->value;

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
        return new PipelineEventTypeRegistry;
    }
}
