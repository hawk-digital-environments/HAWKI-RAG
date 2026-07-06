<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Values;

final readonly class DocumentSearchPayload
{
    /**
     * @param array<string, mixed> $publicMetadata
     * @param array<string, mixed> $storedMetadata
     * @param array<string, mixed> $qdrantPayload
     * @param list<string> $payloadKeys
     * @param list<string> $heapMetadataKeys
     * @param list<string> $documentMetadataKeys
     */
    public function __construct(
        public array $publicMetadata,
        public array $storedMetadata,
        public array $qdrantPayload,
        public array $payloadKeys,
        public array $heapMetadataKeys,
        public array $documentMetadataKeys,
    ) {}
}
