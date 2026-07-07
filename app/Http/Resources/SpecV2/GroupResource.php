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
            'tenant_id' => $this->tenant_id,
            'owner_app' => $this->owner_application_id,
            'metadata' => is_array($this->metadata_json) ? $this->metadata_json : [],
            'member_count' => (int) ($this->members_count ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($this->relationLoaded('members')) {
            $payload['members'] = $this->members->pluck('user_identifier')->values()->all();
        }

        if ($this->relationLoaded('heapGrants')) {
            $payload['assigned_heaps'] = $this->heapGrants
                ->pluck('heap_id')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        return $payload;
    }
}
