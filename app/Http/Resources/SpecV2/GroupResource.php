<?php
declare(strict_types=1);

namespace App\Http\Resources\SpecV2;

use App\Models\SpecV2\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Group */
class GroupResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'tenantId' => $this->tenant_id,
            'ownerApp' => $this->owner_application_id,
            'metadata' => is_array($this->metadata_json) ? $this->metadata_json : [],
            'memberCount' => (int) ($this->members_count ?? 0),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];

        if ($this->relationLoaded('members')) {
            $payload['members'] = $this->members->pluck('user_identifier')->values()->all();
        }

        if ($this->relationLoaded('heapGrants')) {
            $payload['assignedHeaps'] = $this->heapGrants
                ->pluck('heap_id')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return $payload;
    }
}
