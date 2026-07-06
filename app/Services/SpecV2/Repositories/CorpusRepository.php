<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Repositories;

use App\Models\Document;
use App\Models\SpecV2\Corpus;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

#[Singleton]
readonly class CorpusRepository
{
    /**
     * @param list<string>|null $documentIds
     */
    public function paginate(?array $documentIds, int $perPage, int $page): LengthAwarePaginator
    {
        return Corpus::query()
            ->when($documentIds !== null, function ($query) use ($documentIds): void {
                $query->whereHas('documents', function ($documentQuery) use ($documentIds): void {
                    if ($documentIds === []) {
                        $documentQuery->whereRaw('1 = 0');
                    } else {
                        $documentQuery->whereIn('documents.id', $documentIds);
                    }
                });
            })
            ->withCount('documents')
            ->orderByDesc('reference_count')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param list<string>|null $documentIds
     */
    public function findById(string $corpusId, ?array $documentIds = null): ?Corpus
    {
        return Corpus::query()
            ->when($documentIds !== null, function ($query) use ($documentIds): void {
                $query->whereHas('documents', function ($documentQuery) use ($documentIds): void {
                    if ($documentIds === []) {
                        $documentQuery->whereRaw('1 = 0');
                    } else {
                        $documentQuery->whereIn('documents.id', $documentIds);
                    }
                });
            })
            ->withCount('documents')
            ->where('id', $corpusId)
            ->first();
    }

    public function firstOrNew(string $corpusId): Corpus
    {
        return Corpus::query()->firstOrNew(['id' => $corpusId]);
    }

    public function referenceCount(string $corpusId): int
    {
        return Document::query()
            ->where('checksum_sha256', $corpusId)
            ->count();
    }

    public function save(Corpus $corpus): bool
    {
        return $corpus->save();
    }
}
