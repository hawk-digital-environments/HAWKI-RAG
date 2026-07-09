<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Models\AssistantDocument;
use App\Models\IngestionSource;
use App\Services\Assistant\Repositories\AssistantDocumentOutputRepository;
use App\Services\Assistant\Repositories\AssistantDocumentRepository;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class AssistantDocumentSyncService
{
    public function __construct(
        private AssistantDocumentRepository $documents,
        private AssistantDocumentOutputRepository $outputs,
        private IngestionSourceRepository $sources,
        private AssistantDocumentSyncStateResolver $resolver,
    ) {
    }

    public function sync(AssistantDocument $document): AssistantDocument
    {
        if ($document->status === AssistantDocument::STATUS_DELETED || $document->deleted_at !== null) {
            return $this->documents->find($document->assistant_document_id) ?? $document;
        }

        $sourceId = $this->stringValue($document->latest_source_id);
        if ($sourceId === null) {
            return $this->documents->find($document->assistant_document_id) ?? $document;
        }

        $source = $this->sources->findBySourceId($sourceId);
        if ($source === null) {
            return $this->documents->find($document->assistant_document_id) ?? $document;
        }

        $state = $this->resolver->resolve($document, $source);
        if ($state->outputs !== []) {
            $this->outputs->syncActiveOutputs($document, $state->outputs);
        }

        $document = $state->attributes === []
            ? ($this->documents->find($document->assistant_document_id) ?? $document)
            : $this->documents->save($document, $state->attributes);

        $document = $this->documents->find($document->assistant_document_id) ?? $document;
        $this->outputs->backfillScopes(
            $document,
            $document->outputs->where('active', true)->values(),
        );

        return $this->documents->find($document->assistant_document_id) ?? $document;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
