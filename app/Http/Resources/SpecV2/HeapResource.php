<?php
declare(strict_types=1);

namespace App\Http\Resources\SpecV2;

use App\Models\SpecV2\Heap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Heap */
class HeapResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->heapId(),
            'heapId' => $this->heapId(),
            'name' => $this->name,
            'description' => $this->description,
            'tenantId' => $this->tenant_id,
            'ownerApp' => $this->owner_application_id,
            'visibility' => $this->visibility ?? Heap::VISIBILITY_DISCOVERABLE,
            'protected' => (bool) $this->protected,
            'metadata' => is_array($this->metadata_json) ? $this->metadata_json : [],
            'qdrantCollection' => $this->qdrant_collection,
            'neo4jNamespace' => $this->neo4j_namespace,
            'documentCount' => (int) ($this->documents_count ?? 0),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
