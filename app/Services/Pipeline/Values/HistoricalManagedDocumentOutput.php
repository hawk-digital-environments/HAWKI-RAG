<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Values;

final readonly class HistoricalManagedDocumentOutput
{
    public function __construct(
        public ?int $id,
        public ?string $bridgeDocumentId,
        public ?string $qdrantCollection,
        public ?string $sourceId,
        public ?string $taskId,
        public ?string $jobId,
        public ?string $contentHash,
        public int $chunkCount,
        public ?string $status,
        public ?string $indexedAt,
    ) {}
}
