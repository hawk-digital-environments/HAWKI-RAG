<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Document;
use App\Models\SpecV2\Heap;
use App\Services\SpecV2\DocumentSearchPayloadFactory;
use App\Services\SpecV2\DocumentSearchPayloadSyncService;
use App\Services\SpecV2\Repositories\HeapRepository;
use Illuminate\Database\Eloquent\Model;

final class DocumentObserver
{
    /**
     * @var array<int, array{metadata_json: mixed, heap_id: string, collection: mixed}>
     */
    public function __construct(
        private DocumentSearchPayloadFactory $payloads,
        private DocumentSearchPayloadSyncService $documents,
        private HeapRepository $heaps,
    ) {}

    public function saving(Document $document): void
    {
        if ($document->relationLoaded('heap')) {
            $heap = $document->getRelation('heap');
            if (! $heap instanceof Heap || $heap->heapId() !== $document->heapId()) {
                $document->unsetRelation('heap');
            }
        }

        $document->loadMissing('heap');

        $this->capturePreviousState($document);

        if (! $document->heap instanceof Heap) {
            return;
        }

        $document->metadata_json = $this->payloads->withInternalAudit(
            $document,
            $document->heap,
            is_array($document->metadata_json) ? $document->metadata_json : [],
        );
    }

    public function saved(Document $document): void
    {
        if (! $document->wasRecentlyCreated && ! $document->wasChanged(['dataset_id', 'collection', 'metadata_json'])) {
            $this->forgetPreviousState($document);

            return;
        }

        $this->ensurePersistedAuditDocumentId($document);

        $previous = $this->previousState($document);

        $this->documents->syncDocument(
            $document,
            $this->payloads->stalePayloadKeysForSync(
                $previous['metadata_json'] ?? null,
                $this->previousHeapMetadataKeys($this->stringValue($previous['heap_id'] ?? '') ?? $this->stringValue($document->getOriginal('dataset_id')) ?? ''),
            ),
            $this->stringValue($previous['collection'] ?? null)
            ?? $this->stringValue($document->getOriginal('collection')),
        );

        $this->forgetPreviousState($document);
    }

    private function ensurePersistedAuditDocumentId(Document $document): void
    {
        if (trim((string) $document->id) === '') {
            return;
        }

        $metadata = is_array($document->metadata_json) ? $document->metadata_json : [];
        $rawki = is_array($metadata['__rawki'] ?? null) ? $metadata['__rawki'] : [];
        $audit = is_array($rawki['audit'] ?? null) ? $rawki['audit'] : [];
        $documentId = (string) ($audit['documentId'] ?? '');

        if ($documentId === trim((string) $document->id)) {
            return;
        }

        $rawki['audit'] = array_merge([
            'schema' => $audit['schema'] ?? DocumentSearchPayloadFactory::INTERNAL_AUDIT_SCHEMA_VERSION,
            'heap' => $document->heapId() ?? '',
            'documentId' => (string) $document->id,
        ], $audit, [
            'documentId' => (string) $document->id,
        ]);

        $metadata['__rawki'] = $rawki;

        Document::query()->whereKey((string) $document->id)->update(['metadata_json' => $metadata]);
    }

    private function capturePreviousState(Document $document): void
    {
        if ($document->wasRecentlyCreated || $document->exists === false) {
            return;
        }

        $metadata = $document->getOriginal('metadata_json');
        $datasetId = $document->getOriginal('dataset_id');
        $collection = $document->getOriginal('collection');

        self::withPreviousStates()[spl_object_id($document)] = [
            'metadata_json' => is_array($metadata) ? $metadata : [],
            'heap_id' => $this->stringValue($datasetId) ?? '',
            'collection' => $collection,
        ];
    }

    private function previousState(Document $document): array
    {
        return self::withPreviousStates()[spl_object_id($document)] ?? [];
    }

    private function forgetPreviousState(Document $document): void
    {
        unset(self::withPreviousStates()[spl_object_id($document)]);
    }

    /**
     * @return array<int, array{metadata_json: mixed, heap_id: string, collection: mixed}>
     */
    private static function &withPreviousStates(): array
    {
        static $previousStates = [];

        return $previousStates;
    }

    /**
     * @return list<string>
     */
    private function previousHeapMetadataKeys(string $heapId): array
    {
        if ($heapId === '') {
            return [];
        }

        $heap = $this->heaps->findById($heapId);
        if (! $heap instanceof Heap) {
            return [];
        }

        return array_keys($this->payloads->publicMetadata($heap->metadata_json));
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
