<?php

declare(strict_types=1);

namespace App\Services\SpecV2;

use App\Models\Dataset;
use App\Models\SpecV2\Heap;
use App\Services\Heap\HeapStorageCleanupService;
use App\Services\SpecV2\Repositories\HeapRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class HeapDeletionService
{
    public function __construct(
        private HeapRepository $heaps,
        private HeapStorageCleanupService $storageCleanup,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function delete(Heap $heap): array
    {
        $cleanup = $this->storageCleanup->deleteStorage(new Dataset($heap->getAttributes()));
        $cleanupOk = ($cleanup['qdrant']['ok'] ?? false) && ($cleanup['neo4j']['ok'] ?? false);

        return [
            ...$cleanup,
            'heapDeleted' => $cleanupOk ? $this->heaps->delete($heap) : false,
        ];
    }
}
