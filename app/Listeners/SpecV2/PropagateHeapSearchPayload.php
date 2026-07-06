<?php
declare(strict_types=1);

namespace App\Listeners\SpecV2;

use App\Services\SpecV2\DocumentSearchPayloadSyncService;
use App\Services\SpecV2\Events\HeapSearchPayloadChanged;
use App\Services\SpecV2\Repositories\HeapRepository;
use Illuminate\Contracts\Queue\ShouldQueue;

final readonly class PropagateHeapSearchPayload implements ShouldQueue
{
    public function __construct(
        private HeapRepository $heaps,
        private DocumentSearchPayloadSyncService $documents,
    ) {}

    public function handle(HeapSearchPayloadChanged $event): void
    {
        $heap = $this->heaps->findById($event->heapId);
        if ($heap === null) {
            return;
        }

        $this->documents->propagateHeap($heap, $event->previousHeapMetadataKeys);
    }
}
