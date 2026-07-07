<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Repositories;

use App\Models\SpecV2\Heap;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

#[Singleton]
readonly class HeapRepository
{
    /**
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        return Heap::query()
            ->with(['tenant', 'ownerApplication'])
            ->withCount('documents')
            ->when($filters['tenant_id'] ?? null, fn ($query, $tenantId) => $query->where('tenant_id', $tenantId))
            ->when($filters['owner_application_id'] ?? null, fn ($query, $appId) => $query->where('owner_application_id', $appId))
            ->when($filters['visibility'] ?? null, fn ($query, $visibility) => $query->where('visibility', $visibility))
            ->when(array_key_exists('protected', $filters), fn ($query) => $query->where('protected', (bool) $filters['protected']))
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function findById(string $heapId): ?Heap
    {
        $heap = new Heap();

        return Heap::query()
            ->with(['tenant', 'ownerApplication'])
            ->withCount('documents')
            ->where($heap->storageKeyName(), $heapId)
            ->first();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): Heap
    {
        return Heap::query()->create($attributes);
    }

    public function save(Heap $heap): bool
    {
        return $heap->save();
    }

    public function delete(Heap $heap): bool
    {
        return (bool) $heap->delete();
    }
}
