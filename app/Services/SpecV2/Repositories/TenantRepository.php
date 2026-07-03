<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Repositories;

use App\Models\SpecV2\Tenant;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

#[Singleton]
readonly class TenantRepository
{
    public function paginate(int $perPage, int $page): LengthAwarePaginator
    {
        return Tenant::query()
            ->withCount(['applications', 'groups', 'heaps'])
            ->orderBy('name')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function findById(string $tenantId): ?Tenant
    {
        return Tenant::query()
            ->withCount(['applications', 'groups', 'heaps'])
            ->where('id', $tenantId)
            ->first();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Tenant
    {
        return Tenant::query()->create($attributes);
    }
}
