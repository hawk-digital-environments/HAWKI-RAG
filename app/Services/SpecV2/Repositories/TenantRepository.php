<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Repositories;

use App\Models\SpecV2\Tenant;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

#[Singleton]
readonly class TenantRepository
{
    /**
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        return Tenant::query()
            ->withCount(['applications', 'groups', 'heaps'])
            ->when($filters['id'] ?? null, fn ($query, $id) => $query->where('id', $id))
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
