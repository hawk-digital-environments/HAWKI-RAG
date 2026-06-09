<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Values;

enum PipelineEventType: string
{
    case ScrapeRequested = 'scrape.requested';
    case ScrapeMonitorRequested = 'scrape.monitor.requested';
    case PageScraped = 'page.scraped';
    case FileDiscovered = 'file.discovered';
    case FileConverted = 'file.converted';
    case ContentIngested = 'content.ingested';
    case JobFailed = 'job.failed';
}
