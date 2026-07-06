<?php
declare(strict_types=1);

namespace App\Observers;

use App\Models\Document;
use App\Models\SpecV2\Heap;
use App\Services\SpecV2\DocumentSearchPayloadFactory;
use App\Services\SpecV2\DocumentSearchPayloadSyncService;

final readonly class DocumentObserver
{
    public function __construct(
        private DocumentSearchPayloadFactory $payloads,
        private DocumentSearchPayloadSyncService $documents,
    ) {}

    public function saving(Document $document): void
    {
        if ($document->relationLoaded('heap')) {
            $heap = $document->getRelation('heap');
            if (! $heap instanceof Heap || $heap->dataset_id !== $document->dataset_id) {
                $document->unsetRelation('heap');
            }
        }

        $document->loadMissing('heap');

        if (! $document->heap instanceof Heap) {
            return;
        }

        $document->metadata_json = $this->payloads->build($document, $document->heap)->storedMetadata;
    }

    public function saved(Document $document): void
    {
        if (! $document->wasRecentlyCreated && ! $document->wasChanged(['dataset_id', 'collection', 'metadata_json'])) {
            return;
        }

        $previous = $document->getPrevious();

        $this->documents->syncDocument(
            $document,
            $this->payloads->payloadKeysFromStoredMetadata($previous['metadata_json'] ?? null),
            $this->stringValue($previous['collection'] ?? null),
        );
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
