<?php

declare(strict_types=1);

namespace App\Services\Assistant\Repositories;

use App\Models\Document;
use App\Services\Document\Repositories\ManagedIngestionMetadataRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
readonly class AssistantIngestionMetadataRepository
{
    public function __construct(
        private ManagedIngestionMetadataRepository $metadata,
    ) {
    }

    /**
     * @return Collection<int, Document>
     */
    public function documentsForSource(string $sourceId): Collection
    {
        return $this->metadata->documentsForSource($sourceId);
    }

    /**
     * @return array<string, int>
     */
    public function chunkCountsForSource(string $sourceId): array
    {
        return $this->metadata->chunkCountsForSource($sourceId);
    }
}
