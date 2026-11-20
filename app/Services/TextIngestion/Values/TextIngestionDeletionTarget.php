<?php

declare(strict_types=1);

namespace App\Services\TextIngestion\Values;

final readonly class TextIngestionDeletionTarget
{
    public function __construct(
        public string $sourceId,
        public string $datasetId,
    ) {}
}
