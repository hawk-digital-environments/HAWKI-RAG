<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Events;

use App\Services\Pipeline\Values\PipelineJobStatus;
use App\Services\Pipeline\Values\PipelineJobType;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineEventTypeRegistry
{
    public function jobTypeFor(string $eventType): ?string
    {
        return match ($eventType) {
            PipelineEvent::SCRAPE_REQUESTED,
            PipelineEvent::SCRAPE_MONITOR_REQUESTED,
            PipelineEvent::PAGE_SCRAPED => PipelineJobType::Scrape->value,
            PipelineEvent::FILE_DISCOVERED,
            PipelineEvent::FILE_CONVERTED => PipelineJobType::Convert->value,
            PipelineEvent::CONTENT_INGESTED => PipelineJobType::Ingest->value,
            default => null,
        };
    }

    public function terminalStatus(string $status): bool
    {
        return PipelineJobStatus::tryFrom($status)?->isTerminal() ?? false;
    }

    public function jobIdPrefixFor(string $eventType): string
    {
        return match ($this->jobTypeFor($eventType)) {
            PipelineJobType::Scrape->value => PipelineJobType::Scrape->value,
            PipelineJobType::Convert->value => PipelineJobType::Convert->value,
            PipelineJobType::Ingest->value => PipelineJobType::Ingest->value,
            PipelineJobType::Graph->value => PipelineJobType::Graph->value,
            default => 'job',
        };
    }
}
