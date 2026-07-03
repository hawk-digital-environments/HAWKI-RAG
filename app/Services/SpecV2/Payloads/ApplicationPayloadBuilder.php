<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Payloads;

use App\Models\SpecV2\Application;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ApplicationPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function payload(Application $application): array
    {
        return [
            'id' => $application->id,
            'tenantId' => $application->tenant_id,
            'name' => $application->name,
            'description' => $application->description,
            'permissions' => $application->permissions ?? [],
            'metadata' => $application->metadata_json ?? [],
            'heapCount' => (int) ($application->heaps_count ?? 0),
            'groupCount' => (int) ($application->groups_count ?? 0),
            'createdAt' => $application->created_at?->toIso8601String(),
            'updatedAt' => $application->updated_at?->toIso8601String(),
        ];
    }
}
