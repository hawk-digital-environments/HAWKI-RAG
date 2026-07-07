<?php

declare(strict_types=1);

namespace App\Services\SpecV2;

use App\Models\Document;
use App\Models\SpecV2\Heap;
use App\Services\SpecV2\Values\DocumentSearchPayload;
use App\Services\SpecV2\Values\DocumentStoredMetadata;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class DocumentSearchPayloadFactory
{
    public const INTERNAL_KEY = DocumentStoredMetadata::INTERNAL_KEY;
    public const INTERNAL_AUDIT_SCHEMA_VERSION = DocumentStoredMetadata::AUDIT_SCHEMA_VERSION;
    private const SYSTEM_KEYS = ['heap', 'document_id', 'owner_app', 'visibility', 'protected'];

    /**
     * @return array<string, mixed>
     */
    public function publicMetadata(mixed $metadata): array
    {
        return DocumentStoredMetadata::publicMetadata($metadata);
    }

    /**
     * @return list<string>
     */
    public function publicMetadataKeys(mixed $metadata): array
    {
        return array_values(array_unique(array_map(
            static fn (string $key): string => trim($key),
            array_keys($this->publicMetadata($metadata)),
        )));
    }

    /**
     * @return list<string>
     */
    public function payloadKeysFromStoredMetadata(mixed $metadata): array
    {
        return $this->publicMetadataKeys($metadata);
    }

    /**
     * @param array<string, mixed>|null $previousMetadata
     * @param list<string> $previousHeapMetadataKeys
     * @return list<string>
     */
    public function stalePayloadKeysForSync(mixed $previousMetadata, array $previousHeapMetadataKeys = []): array
    {
        return array_values(array_unique([
            ...$this->publicMetadataKeys($previousMetadata),
            ...$previousHeapMetadataKeys,
        ]));
    }

    /**
     * @param array<string, mixed> $documentMetadata
     * @return array<string, mixed>
     */
    public function withInternalAudit(Document $document, Heap $heap, array $documentMetadata): array
    {
        return DocumentStoredMetadata::forDocument($heap->heapId(), (string) $document->id, $documentMetadata)->toArray();
    }

    public function build(Document $document, Heap $heap): DocumentSearchPayload
    {
        $publicMetadata = $this->publicMetadata($document->metadata_json);
        $heapMetadata = $this->heapMetadata($heap);
        $searchPayload = array_replace(
            $heapMetadata,
            $publicMetadata,
            $this->systemPayload($document, $heap),
        );

        return new DocumentSearchPayload(
            publicMetadata: $publicMetadata,
            internalAudit: [
                'schema' => self::INTERNAL_AUDIT_SCHEMA_VERSION,
                'documentId' => (string) $document->id,
                'heap' => $heap->heapId(),
            ],
            qdrantPayload: $searchPayload,
            payloadKeys: array_values(array_unique([
                ...array_keys($searchPayload),
                ...self::SYSTEM_KEYS,
            ])),
            heapMetadataKeys: array_values(array_keys($heapMetadata)),
            documentMetadataKeys: array_values(array_keys($publicMetadata)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function heapMetadata(Heap $heap): array
    {
        return is_array($heap->metadata_json) ? $heap->metadata_json : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function systemPayload(Document $document, Heap $heap): array
    {
        return [
            'heap' => $heap->heapId(),
            'document_id' => (string) $document->id,
            'owner_app' => $heap->owner_application_id,
            'visibility' => $heap->visibility ?? Heap::VISIBILITY_DISCOVERABLE,
            'protected' => (bool) $heap->protected,
        ];
    }
}
