<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Repositories;

use App\Models\Document;
use App\Models\SpecV2\Corpus;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\File;

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

    public function syncFromDocument(Document $document): Corpus
    {
        $corpusId = $document->checksum_sha256;
        $corpus = Corpus::query()->firstOrNew(['id' => $corpusId]);

        if ($corpus->content === null && $document->storage_path && File::exists($document->storage_path)) {
            $corpus->content = File::get($document->storage_path);
        }

        $corpus->reference_count = Document::query()
            ->where('checksum_sha256', $corpusId)
            ->count();
        $corpus->metadata_json = is_array($corpus->metadata_json) ? $corpus->metadata_json : [];
        $corpus->save();

        if ($document->corpus_id !== $corpusId) {
            $document->corpus_id = $corpusId;
            $document->save();
        }

        return $corpus;
    }
}
