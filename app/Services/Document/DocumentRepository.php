<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\Document;
use App\Models\PipelineJob;
use App\Models\SpecV2\Heap;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

#[Singleton]
readonly class DocumentRepository
{
    public function findById(string $documentId): ?Document
    {
        if (trim($documentId) === '') {
            return null;
        }

        return Document::query()->where('id', $documentId)->first();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Document
    {
        return Document::query()->create($attributes);
    }

    public function save(Document $document): bool
    {
        return $document->save();
    }

    public function delete(Document $document): bool
    {
        return (bool) $document->delete();
    }

    public function latestCompleted(): ?Document
    {
        return Document::query()
            ->where('status', Document::STATUS_COMPLETED)
            ->orderByDesc('updated_at')
            ->first();
    }

    /**
     * @param list<string> $externalIds
     * @return Collection<int, Document>
     */
    public function findByExternalIds(array $externalIds): Collection
    {
        $externalIds = array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => trim($value),
            $externalIds,
        ))));

        if ($externalIds === []) {
            return collect();
        }

        return Document::query()
            ->whereIn('external_id', $externalIds)
            ->get();
    }

    /**
     * @param list<string> $documentIds
     * @param array<string, mixed> $filters
     * @return Collection<int, Document>
     */
    public function findManyByIds(array $documentIds, array $filters = []): Collection
    {
        $documentIds = array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => trim($value),
            $documentIds,
        ))));

        if ($documentIds === []) {
            return collect();
        }

        return $this->filteredQuery([
            ...$filters,
            'document_ids' => $documentIds,
        ])->get();
    }

    /**
     * @return Collection<int, Document>
     */
    public function list(array $filters, int $limit): Collection
    {
        return $this->filteredQuery($filters)
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['documents.*'], 'page', $page);
    }

    /**
     * @return Collection<int, PipelineJob>
     */
    public function relatedJobs(Document $document, ?string $taskId, ?string $jobId, int $limit = 50): Collection
    {
        return PipelineJob::query()
            ->where(function (Builder $inner) use ($document, $taskId, $jobId): void {
                if ($taskId) {
                    $inner->orWhere('task_id', $taskId);
                }

                if ($jobId) {
                    $inner->orWhere('job_id', $jobId)
                        ->orWhere('parent_job_id', $jobId);
                }

                if ($document->checksum_sha256) {
                    $inner->orWhere('content_hash', $document->checksum_sha256);
                }

                if ($document->storage_path) {
                    $inner->orWhere('local_path', $document->storage_path);
                }
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    private function filteredQuery(array $filters): Builder
    {
        $document = new Document();
        $heap = new Heap();
        $documentTable = $document->getTable();
        $heapStorageColumn = $document->heapStorageColumn();

        $query = Document::query()->select($documentTable.'.*');
        $heapId = $this->stringValue($filters['heap_id'] ?? $filters['heapId'] ?? null);
        $tenantId = $this->stringValue($filters['tenant_id'] ?? null);
        $ownerApplicationId = $this->stringValue($filters['owner_application_id'] ?? null);
        $search = $this->stringValue($filters['q'] ?? $filters['search'] ?? null);
        $documentIds = array_values(array_filter(array_map(
            fn (mixed $value): ?string => is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null,
            is_array($filters['document_ids'] ?? null) ? $filters['document_ids'] : (is_array($filters['documentIds'] ?? null) ? $filters['documentIds'] : []),
        )));

        if ($tenantId !== null || $ownerApplicationId !== null) {
            $query->join($heap->getTable().' as heaps', 'heaps.'.$heap->storageKeyName(), '=', $documentTable.'.'.$heapStorageColumn);
        }

        if ($heapId) {
            $query->where($documentTable.'.'.$heapStorageColumn, $heapId);
        }

        if ($tenantId !== null) {
            $query->where('heaps.tenant_id', $tenantId);
        }

        if ($ownerApplicationId !== null) {
            $query->where('heaps.owner_application_id', $ownerApplicationId);
        }

        if (($filters['document_ids'] ?? $filters['documentIds'] ?? null) !== null) {
            if ($documentIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn($documentTable.'.id', $documentIds);
            }
        }

        if ($search) {
            $query->where(function (Builder $inner) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $inner
                    ->where('documents.source_url', 'like', $like)
                    ->orWhere('documents.storage_path', 'like', $like)
                    ->orWhere('documents.title', 'like', $like)
                    ->orWhere('documents.original_filename', 'like', $like)
                    ->orWhere('documents.checksum_sha256', 'like', $like);
            });
        }

        return $query->distinct();
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
