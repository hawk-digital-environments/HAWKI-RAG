<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\ManagedDocument;
use App\Services\Document\Repositories\ManagedDocumentOutputRepository;
use App\Services\Document\Repositories\ManagedDocumentRepository;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ManagedDocumentSyncService
{
    public function __construct(
        private ManagedDocumentRepository $documents,
        private ManagedDocumentOutputRepository $outputs,
        private IngestionSourceRepository $sources,
        private ManagedDocumentSyncStateResolver $resolver,
    ) {
    }

    public function sync(ManagedDocument $document): ManagedDocument
    {
        if ($document->status === ManagedDocument::STATUS_DELETED || $document->deleted_at !== null) {
            return $this->documents->find($document->documentId()) ?? $document;
        }

        $sourceId = $this->stringValue($document->latest_source_id);
        if ($sourceId === null) {
            return $this->documents->find($document->documentId()) ?? $document;
        }

        $source = $this->sources->findBySourceId($sourceId);
        if ($source === null) {
            return $this->documents->find($document->documentId()) ?? $document;
        }

        $state = $this->resolver->resolve($document, $source);
        if ($state->outputs !== []) {
            $this->outputs->syncActiveOutputs($document, $state->outputs);
        }

        $document = $state->attributes === []
            ? ($this->documents->find($document->documentId()) ?? $document)
            : $this->documents->save($document, $state->attributes);

        $document = $this->documents->find($document->documentId()) ?? $document;
        $this->outputs->backfillScopes(
            $document,
            $document->outputs->where('active', true)->values(),
        );

        return $this->documents->find($document->documentId()) ?? $document;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
