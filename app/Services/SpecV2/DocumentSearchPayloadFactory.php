<?php
declare(strict_types=1);

namespace App\Services\SpecV2;

use App\Models\Document;
use App\Models\SpecV2\Heap;
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
     * @return array<string, mixed>
     */
    public function storedMetadata(Document $document, Heap $heap): array
    {
        $metadata = $this->publicMetadata($document->metadata_json);
        $metadata[self::INTERNAL_KEY] = [
            'heap_context' => [
                'heap' => $heap->dataset_id,
                'owner_app' => $heap->owner_application_id,
                'visibility' => $heap->visibility ?? Heap::VISIBILITY_DISCOVERABLE,
                'protected' => (bool) $heap->protected,
                'metadata_keys' => array_keys($this->heapMetadata($heap)),
            ],
            'search_payload' => array_replace(
                $this->heapMetadata($heap),
                $metadata,
                $this->systemPayload($document, $heap),
            ),
        ];

        return $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function qdrantHeapPayload(Document $document, Heap $heap): array
    {
        return array_replace(
            $this->heapMetadata($heap),
            $this->systemPayload($document, $heap),
        );
    }

    /**
     * @return list<string>
     */
    public function qdrantHeapPayloadKeys(Heap $heap): array
    {
        return array_values(array_unique([
            ...array_keys($this->heapMetadata($heap)),
            ...self::SYSTEM_KEYS,
        ]));
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
            'heap' => $heap->dataset_id,
            'document_id' => (string) $document->id,
            'owner_app' => $heap->owner_application_id,
            'visibility' => $heap->visibility ?? Heap::VISIBILITY_DISCOVERABLE,
            'protected' => (bool) $heap->protected,
        ];
    }
}
