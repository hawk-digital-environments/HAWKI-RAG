<?php

declare(strict_types=1);

namespace App\Services\Document\Repositories;

use App\Models\RagIngestionArtifact;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ManagedIngestionMetadataRepository
{
    public function latestArtifactForSource(string $sourceId): ?RagIngestionArtifact
    {
        return RagIngestionArtifact::query()
            ->where('source_id', $sourceId)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }
}
