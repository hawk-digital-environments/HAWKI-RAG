<?php
declare(strict_types=1);

namespace App\Http\Resources\SpecV2;

use App\Models\SpecV2\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Application */
class ApplicationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenantId' => $this->tenant_id,
            'name' => $this->name,
            'description' => $this->description,
            'permissions' => is_array($this->permissions) ? $this->permissions : [],
            'metadata' => is_array($this->metadata_json) ? $this->metadata_json : [],
            'heapCount' => (int) ($this->heaps_count ?? 0),
            'groupCount' => (int) ($this->groups_count ?? 0),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}

