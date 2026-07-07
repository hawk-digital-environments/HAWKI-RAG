<?php
declare(strict_types=1);

namespace App\Services\SpecV2;

use App\Models\Document;
use App\Models\SpecV2\Heap;
use App\Services\SpecV2\Values\DocumentSearchPayload;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class DocumentSearchPayloadFactory
{
    public const INTERNAL_KEY = '__rawki';
    private const SYSTEM_KEYS = ['heap', 'document_id', 'owner_app', 'visibility', 'protected'];

    /**
     * @return array<string, mixed>
     */
    public function publicMetadata(mixed $metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }

        unset($metadata[self::INTERNAL_KEY]);

        return $metadata;
    }

    /**
     * @return list<string>
     */
    public function payloadKeysFromStoredMetadata(mixed $metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }

        $internal = is_array($metadata[self::INTERNAL_KEY] ?? null) ? $metadata[self::INTERNAL_KEY] : [];
        $searchPayload = is_array($internal['search_payload'] ?? null) ? $internal['search_payload'] : [];

        return array_values(array_unique(array_map(
            static fn (string $key): string => trim($key),
            array_keys($searchPayload),
        )));
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

        $publicMetadata[self::INTERNAL_KEY] = [
            'heap_context' => [
                'heap' => $heap->heapId(),
                'owner_app' => $heap->owner_application_id,
                'visibility' => $heap->visibility ?? Heap::VISIBILITY_DISCOVERABLE,
                'protected' => (bool) $heap->protected,
                'heap_metadata_keys' => array_keys($heapMetadata),
                'document_metadata_keys' => array_keys($this->publicMetadata($document->metadata_json)),
            ],
            'search_payload' => $searchPayload,
        ];

        return new DocumentSearchPayload(
            publicMetadata: $this->publicMetadata($document->metadata_json),
            storedMetadata: $publicMetadata,
            qdrantPayload: $searchPayload,
            payloadKeys: array_values(array_unique([
                ...array_keys($searchPayload),
                ...self::SYSTEM_KEYS,
            ])),
            heapMetadataKeys: array_values(array_keys($heapMetadata)),
            documentMetadataKeys: array_values(array_keys($this->publicMetadata($document->metadata_json))),
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
