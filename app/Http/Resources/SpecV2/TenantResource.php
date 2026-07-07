<?php
declare(strict_types=1);

namespace App\Http\Resources\SpecV2;

use App\Models\SpecV2\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
class TenantResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'metadata' => is_array($this->metadata_json) ? $this->metadata_json : [],
            'application_count' => (int) ($this->applications_count ?? 0),
            'group_count' => (int) ($this->groups_count ?? 0),
            'heap_count' => (int) ($this->heaps_count ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
