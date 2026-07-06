<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Repositories;

use App\Models\SpecV2\HeapGrant;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
readonly class HeapGrantRepository
{
    /**
     * @return Collection<int, HeapGrant>
     */
    public function listForHeap(string $heapId): Collection
    {
        return HeapGrant::query()
            ->with('group')
            ->where('heap_id', $heapId)
            ->orderBy('group_id')
            ->get();
    }

    /**
     * @param list<string> $groupIds
     */
    public function replace(string $heapId, array $groupIds): void
    {
        HeapGrant::query()->where('heap_id', $heapId)->delete();
        $this->add($heapId, $groupIds);
    }

    /**
     * @param list<string> $groupIds
     */
    public function add(string $heapId, array $groupIds): void
    {
        foreach ($groupIds as $groupId) {
            HeapGrant::query()->updateOrCreate([
                'heap_id' => $heapId,
                'group_id' => $groupId,
            ]);
        }
    }

    /**
     * @param list<string> $groupIds
     */
    public function remove(string $heapId, array $groupIds): void
    {
        if ($groupIds === []) {
            return;
        }

        HeapGrant::query()
            ->where('heap_id', $heapId)
            ->whereIn('group_id', $groupIds)
            ->delete();
    }
}
