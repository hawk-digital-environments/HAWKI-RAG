<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Repositories;

use App\Models\SpecV2\Group;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

#[Singleton]
readonly class GroupRepository
{
    /**
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        return Group::query()
            ->with(['tenant', 'ownerApplication'])
            ->withCount('members')
            ->when($filters['tenant_id'] ?? null, fn ($query, $tenantId) => $query->where('tenant_id', $tenantId))
            ->when($filters['owner_application_id'] ?? null, fn ($query, $appId) => $query->where('owner_application_id', $appId))
            ->orderBy('name')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function findById(string $groupId): ?Group
    {
        return Group::query()
            ->with(['tenant', 'ownerApplication'])
            ->withCount('members')
            ->where('id', $groupId)
            ->first();
    }

    /**
     * @param list<string> $groupIds
     * @return Collection<int, Group>
     */
    public function findByIds(array $groupIds): Collection
    {
        return Group::query()
            ->whereIn('id', $groupIds)
            ->get();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Group
    {
        return Group::query()->create($attributes);
    }

    public function delete(Group $group): bool
    {
        return (bool) $group->delete();
    }
}
