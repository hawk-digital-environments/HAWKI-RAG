<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineJob;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineEventTypeRegistry
{
    public function jobTypeFor(string $eventType): ?string
    {
        return match ($eventType) {
            PipelineEvent::SCRAPE_REQUESTED,
            PipelineEvent::SCRAPE_MONITOR_REQUESTED,
            PipelineEvent::PAGE_SCRAPED => PipelineJob::TYPE_SCRAPE,
            PipelineEvent::FILE_DISCOVERED,
            PipelineEvent::FILE_CONVERTED => PipelineJob::TYPE_CONVERT,
            PipelineEvent::CONTENT_INGESTED => PipelineJob::TYPE_INGEST,
            default => null,
        };
    }

    public function terminalStatus(string $status): bool
    {
        return in_array($status, [
            PipelineJob::STATUS_COMPLETED,
            PipelineJob::STATUS_FAILED,
            PipelineJob::STATUS_SKIPPED,
        ], true);
    }

    public function jobIdPrefixFor(string $eventType): string
    {
        return match ($this->jobTypeFor($eventType)) {
            PipelineJob::TYPE_SCRAPE => 'scrape',
            PipelineJob::TYPE_CONVERT => 'convert',
            PipelineJob::TYPE_INGEST => 'ingest',
            PipelineJob::TYPE_GRAPH => 'graph',
            default => 'job',
        };
    }
}
