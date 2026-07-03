<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Payloads;

use App\Models\SpecV2\Tenant;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class TenantPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function payload(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'metadata' => $tenant->metadata_json ?? [],
            'applicationCount' => (int) ($tenant->applications_count ?? 0),
            'groupCount' => (int) ($tenant->groups_count ?? 0),
            'heapCount' => (int) ($tenant->heaps_count ?? 0),
            'createdAt' => $tenant->created_at?->toIso8601String(),
            'updatedAt' => $tenant->updated_at?->toIso8601String(),
        ];
    }
}
