<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Payloads;

use App\Models\SpecV2\Heap;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class HeapPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function payload(Heap $heap): array
    {
        return [
            'id' => $heap->dataset_id,
            'datasetId' => $heap->dataset_id,
            'name' => $heap->name,
            'description' => $heap->description,
            'tenantId' => $heap->tenant_id,
            'ownerApp' => $heap->owner_application_id,
            'visibility' => $heap->visibility ?? Heap::VISIBILITY_DISCOVERABLE,
            'protected' => (bool) $heap->protected,
            'metadata' => $heap->metadata_json ?? [],
            'qdrantCollection' => $heap->qdrant_collection,
            'neo4jNamespace' => $heap->neo4j_namespace,
            'documentCount' => (int) ($heap->documents_count ?? 0),
            'createdAt' => $heap->created_at?->toIso8601String(),
            'updatedAt' => $heap->updated_at?->toIso8601String(),
        ];
    }
}
