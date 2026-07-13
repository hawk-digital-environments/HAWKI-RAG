<?php

declare(strict_types=1);

namespace App\Services\Document\Repositories;

use App\Models\Document;
use App\Models\IngestedPage;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

#[Singleton]
readonly class ManagedIngestionMetadataRepository
{
    public function __construct(
        private DatabaseManager $database,
    ) {
    }

    /**
     * @return Collection<int, Document>
     */
    public function documentsForSource(string $sourceId): Collection
    {
        $query = Document::query()
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at');

        $driver = $this->database->connection()->getDriverName();

        if ($driver === 'pgsql') {
            $query->whereRaw("(metadata_json::jsonb ->> 'source_id') = ?", [$sourceId]);
        } elseif ($driver === 'sqlite') {
            $query->whereRaw("json_extract(metadata_json, '$.source_id') = ?", [$sourceId]);
        } else {
            $query->whereRaw("json_unquote(json_extract(metadata_json, '$.source_id')) = ?", [$sourceId]);
        }

        return $query->get();
    }

    /**
     * @return array<string, int>
     */
    public function chunkCountsForSource(string $sourceId): array
    {
        return IngestedPage::query()
            ->selectRaw('doc_id, coalesce(sum(chunks_count), 0) as aggregate_chunks')
            ->where('source_id', $sourceId)
            ->whereNotNull('doc_id')
            ->groupBy('doc_id')
            ->pluck('aggregate_chunks', 'doc_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
    }
}
