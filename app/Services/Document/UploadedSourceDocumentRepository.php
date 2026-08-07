<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\IngestionSource;
use App\Models\PipelineJob;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

#[Singleton]
readonly class UploadedSourceDocumentRepository
{
    /**
     * @return Collection<int, PipelineJob>
     */
    public function pipelineJobs(string $sourceUrl, ?string $contentHash = null): Collection
    {
        return PipelineJob::query()
            ->where('source_url', $sourceUrl)
            ->when($contentHash, fn (Builder $query): Builder => $query->where('content_hash', $contentHash))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(25)
            ->get();
    }

    /**
     * @return Collection<int, IngestionSource>
     */
    public function ingestionSources(string $sourceUrl, ?string $contentHash = null): Collection
    {
        return IngestionSource::query()
            ->where('source_url', $sourceUrl)
            ->when($contentHash, fn (Builder $query): Builder => $query->where('content_hash', $contentHash))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(25)
            ->get();
    }
}
