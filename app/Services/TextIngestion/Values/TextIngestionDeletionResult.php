<?php

declare(strict_types=1);

namespace App\Services\TextIngestion\Values;

final readonly class TextIngestionDeletionResult
{
    public function __construct(
        public string $sourceId,
        public string $datasetId,
        public bool $replayed,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'dataset_id' => $this->datasetId,
            'status' => 'deleted',
            'deleted' => true,
            'replayed' => $this->replayed,
        ];
    }
}
