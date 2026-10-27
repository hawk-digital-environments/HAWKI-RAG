<?php

declare(strict_types=1);

namespace App\Services\Document\Repositories;

use App\Models\ManagedDocument;
use App\Models\ManagedDocumentDeletion;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ManagedDocumentDeletionRepository
{
    public function lockDocument(string $id): ManagedDocument
    {
        return ManagedDocument::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function latest(string $id): ?ManagedDocumentDeletion
    {
        return ManagedDocumentDeletion::query()->where('document_id', $id)->latest('created_at')->latest('operation_id')->first();
    }

    public function blocking(string $documentId): ?ManagedDocumentDeletion
    {
        return ManagedDocumentDeletion::query()->where('document_id', $documentId)
            ->whereIn('status', ['preparing', 'pending', 'failed', 'completed'])->first();
    }

    /**
     * Bridge document IDs whose sink cleanup is not yet verified. Content in
     * this window is already logically deleted and must stay hidden from
     * retrieval until the deletion workflow reports verified completion.
     *
     * @return list<string>
     */
    public function blockingDocIdsForDataset(string $datasetId): array
    {
        return ManagedDocumentDeletion::query()->where('dataset_id', $datasetId)
            ->whereIn('status', ['preparing', 'pending', 'failed'])
            ->orderBy('operation_id')
            ->get()
            ->flatMap(fn (ManagedDocumentDeletion $operation): array => array_column($operation->workflow_input['targets'] ?? [], 'doc_id'))
            ->filter(fn (mixed $docId): bool => is_string($docId) && $docId !== '')
            ->map(fn (string $docId): string => $docId)
            ->unique()
            ->take(500)
            ->values()
            ->all();
    }

    public function find(string $id): ?ManagedDocumentDeletion
    {
        return ManagedDocumentDeletion::query()->find($id);
    }

    public function lock(string $id): ManagedDocumentDeletion
    {
        return ManagedDocumentDeletion::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function forRequest(string $documentId, string $purpose, ?string $key): ?ManagedDocumentDeletion
    {
        return $key === null ? null : ManagedDocumentDeletion::query()
            ->where('document_id', $documentId)->where('purpose', $purpose)->where('request_key', $key)->first();
    }

    public function create(array $attributes): ManagedDocumentDeletion
    {
        return ManagedDocumentDeletion::query()->create($attributes);
    }

    public function save(ManagedDocumentDeletion $operation, array $attributes): ManagedDocumentDeletion
    {
        $operation->forceFill($attributes)->save();

        return $operation->refresh();
    }
}
