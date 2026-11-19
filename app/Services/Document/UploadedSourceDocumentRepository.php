<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\IngestionSource;
use App\Models\PipelineJob;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

#[Singleton]
readonly class UploadedSourceDocumentRepository
{
    public function __construct(private DatabaseManager $database) {}

    /**
     * @return Collection<int, PipelineJob>
     */
    public function pipelineJobs(string $sourceUrl, ?string $contentHash = null): Collection
    {
        if (! $this->database->connection()->getSchemaBuilder()->hasTable('pipeline_jobs')) {
            return new Collection;
        }

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
        if (! $this->database->connection()->getSchemaBuilder()->hasTable('ingestion_sources')) {
            return new Collection;
        }

        return IngestionSource::query()
            ->where('source_url', $sourceUrl)
            ->when($contentHash, fn (Builder $query): Builder => $query->where('content_hash', $contentHash))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(25)
            ->get();
    }
}
