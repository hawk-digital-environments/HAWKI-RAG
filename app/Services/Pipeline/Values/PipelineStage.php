<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Values;

enum PipelineStage: string
{
    case Scrape = 'scrape';
    case Convert = 'convert';
    case Ingest = 'ingest';
}
