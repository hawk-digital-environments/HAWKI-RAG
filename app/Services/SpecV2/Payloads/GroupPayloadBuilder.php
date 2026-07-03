<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Payloads;

use App\Models\SpecV2\Group;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class GroupPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function payload(Group $group, bool $includeMembers = false): array
    {
        $payload = [
            'id' => $group->id,
            'name' => $group->name,
            'tenantId' => $group->tenant_id,
            'ownerApp' => $group->owner_application_id,
            'description' => $group->description,
            'metadata' => $group->metadata_json ?? [],
            'memberCount' => (int) ($group->members_count ?? 0),
            'assignedHeaps' => [],
            'createdAt' => $group->created_at?->toIso8601String(),
            'updatedAt' => $group->updated_at?->toIso8601String(),
        ];

        if ($includeMembers) {
            $payload['members'] = $group->relationLoaded('members')
                ? $group->members->pluck('user_identifier')->all()
                : [];
        }

        return $payload;
    }
}
