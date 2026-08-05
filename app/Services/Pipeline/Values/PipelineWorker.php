<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Values;

enum PipelineWorker: string
{
    case Scraper = 'scraper';
    case Converter = 'converter';
    case Indexer = 'indexer';

    public function stage(): PipelineStage
    {
        return match ($this) {
            self::Scraper => PipelineStage::Scrape,
            self::Converter => PipelineStage::Convert,
            self::Indexer => PipelineStage::Ingest,
        };
    }

    public function acceptsActivity(string $activityId): bool
    {
        return in_array($activityId, match ($this) {
            self::Scraper => ['scrape_source'],
            self::Converter => ['inspect_and_convert_files'],
            self::Indexer => ['ingest_markdown_files', 'mark_source_ready'],
        }, true);
    }
}
