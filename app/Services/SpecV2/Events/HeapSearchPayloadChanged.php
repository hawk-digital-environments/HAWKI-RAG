<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Events;

final readonly class HeapSearchPayloadChanged
{
    /**
     * @param list<string> $previousHeapMetadataKeys
     */
    public function __construct(
        public string $heapId,
        public array $previousHeapMetadataKeys,
    ) {}
}
