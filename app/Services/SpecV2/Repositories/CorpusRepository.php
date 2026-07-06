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
    public function paginate(int $perPage, int $page): LengthAwarePaginator
    {
        return Corpus::query()
            ->withCount('documents')
            ->orderByDesc('reference_count')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function findById(string $corpusId): ?Corpus
    {
        return Corpus::query()
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
