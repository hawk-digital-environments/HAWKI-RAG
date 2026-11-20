<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\ManagedDocument;
use App\Services\Document\Repositories\ManagedDocumentDeletionRepository;
use App\Services\Document\Repositories\ManagedDocumentOutputRepository;
use App\Services\Document\Repositories\ManagedDocumentRepository;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use App\Services\Pipeline\Repositories\PipelineTransactionRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ManagedDocumentSyncService
{
    public function __construct(
        private ManagedDocumentRepository $documents,
        private ManagedDocumentOutputRepository $outputs,
        private IngestionSourceRepository $sources,
        private ManagedDocumentSyncStateResolver $resolver,
        private ManagedDocumentDeletionRepository $deletions,
        private PipelineTransactionRepository $transactions,
    ) {}

    public function sync(ManagedDocument $document): ManagedDocument
    {
        return $this->transactions->run(function () use ($document): ManagedDocument {
            $document = $this->deletions->lockDocument($document->document_id);
            if ($document->status === ManagedDocument::STATUS_DELETED || $document->deleted_at !== null
                || $this->deletions->blocking($document->document_id) !== null) {
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

            $document = $this->documents->save($document, $state->attributes);
            $this->outputs->backfillScopes($document->outputs->where('active', true)->values());

            return $document;
        });
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
