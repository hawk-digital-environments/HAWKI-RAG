<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Values;

final readonly class HistoricalManagedDocument
{
    /**
     * @param  list<HistoricalManagedDocumentOutput>  $outputs
     */
    public function __construct(
        public string $documentId,
        public ?string $datasetId,
        public ?string $datasetQdrantCollection,
        public ?string $sourceType,
        public ?string $sourceChecksum,
        public ?string $status,
        public ?string $latestSourceId,
        public ?string $latestTaskId,
        public ?string $latestJobId,
        public ?string $indexedAt,
        public ?string $deletedAt,
        public ?string $jobSourceHashClaim,
        public ?string $jobTaskId,
        public ?string $jobSourceId,
        public ?string $sourceUploadHashClaim,
        public ?string $sourceTaskId,
        public ?string $sourceDatasetId,
        public ?string $taskUploadHashClaim,
        public ?string $taskDatasetId,
        public array $outputs,
    ) {}
}
