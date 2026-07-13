<?php

declare(strict_types=1);

namespace App\Services\Document\Repositories;

use App\Models\ManagedDocument;
use App\Services\Document\Values\ManagedDocumentId;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

#[Singleton]
readonly class ManagedDocumentRepository
{
    public function nextManagedDocumentId(): ManagedDocumentId
    {
        return ManagedDocumentId::generate();
    }

    public function find(ManagedDocumentId|string $managedDocumentId): ?ManagedDocument
    {
        return ManagedDocument::query()
            ->with(['outputs' => fn ($query) => $query->orderByDesc('active')->orderBy('id')])
            ->where('assistant_document_id', $this->managedDocumentIdValue($managedDocumentId))
            ->first();
    }

    public function latestIndexed(): ?ManagedDocument
    {
        return $this->queryWithOutputs()
            ->whereNull('deleted_at')
            ->where('status', ManagedDocument::STATUS_INDEXED)
            ->orderByDesc('indexed_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * @return Collection<int, ManagedDocument>
     */
    public function forLatestTaskId(string $taskId): Collection
    {
        return $this->queryWithOutputs()
            ->whereNull('deleted_at')
            ->where('latest_task_id', $taskId)
            ->orderByDesc('indexed_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @return Collection<int, ManagedDocument>
     */
    public function forLatestJobId(string $jobId): Collection
    {
        return $this->queryWithOutputs()
            ->whereNull('deleted_at')
            ->where('latest_job_id', $jobId)
            ->orderByDesc('indexed_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @return Collection<int, ManagedDocument>
     */
    public function forLatestSourceId(string $sourceId): Collection
    {
        return $this->queryWithOutputs()
            ->whereNull('deleted_at')
            ->where('latest_source_id', $sourceId)
            ->orderByDesc('indexed_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @param array<string, mixed> $filters
     * @return Collection<int, ManagedDocument>
     */
    public function list(array $filters, int $limit): Collection
    {
        return $this->filteredQuery($filters)
            ->with(['outputs' => fn ($query) => $query->orderByDesc('active')->orderBy('id')])
            ->orderByDesc('indexed_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): ManagedDocument
    {
        return ManagedDocument::query()->create($attributes)->refresh();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function save(ManagedDocument $document, array $attributes): ManagedDocument
    {
        $document->forceFill($attributes)->save();

        return $this->find($document->documentId()) ?? $document->refresh();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function filteredQuery(array $filters): Builder
    {
        $query = ManagedDocument::query()
            ->whereNull('deleted_at');

        $datasetId = $this->stringValue($filters['dataset_id'] ?? $filters['datasetId'] ?? null);
        $search = $this->stringValue($filters['q'] ?? $filters['search'] ?? null);

        if ($datasetId !== null) {
            $query->where('dataset_id', $datasetId);
        }

        if ($search !== null) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('assistant_document_id', 'like', $like)
                    ->orWhere('display_name', 'like', $like)
                    ->orWhere('source_url', 'like', $like)
                    ->orWhere('source_checksum_sha256', 'like', $like)
                    ->orWhere('latest_source_id', 'like', $like)
                    ->orWhere('latest_task_id', 'like', $like)
                    ->orWhere('latest_job_id', 'like', $like);
            });
        }

        return $query;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function managedDocumentIdValue(ManagedDocumentId|string $managedDocumentId): string
    {
        return ($managedDocumentId instanceof ManagedDocumentId
            ? $managedDocumentId
            : ManagedDocumentId::fromString($managedDocumentId))
            ->value;
    }

    private function queryWithOutputs(): Builder
    {
        return ManagedDocument::query()
            ->with(['outputs' => fn ($query) => $query->orderByDesc('active')->orderBy('id')]);
    }
}
