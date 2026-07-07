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
            'heap_id' => $this->heapId(),
            'name' => $this->name,
            'description' => $this->description,
            'tenant_id' => $this->tenant_id,
            'owner_app' => $this->owner_application_id,
            'visibility' => $this->visibility ?? Heap::VISIBILITY_DISCOVERABLE,
            'protected' => (bool) $this->protected,
            'metadata' => is_array($this->metadata_json) ? $this->metadata_json : [],
            'qdrant_collection' => $this->qdrant_collection,
            'neo4j_namespace' => $this->neo4j_namespace,
            'document_count' => (int) ($this->documents_count ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
