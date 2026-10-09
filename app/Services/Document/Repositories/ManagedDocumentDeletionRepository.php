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

    public function blocksSync(string $id): bool
    {
        return ManagedDocumentDeletion::query()->where('document_id', $id)
            ->whereIn('status', ['pending', 'failed', 'completed'])->exists();
    }
}
