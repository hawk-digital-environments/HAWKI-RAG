<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Repositories;

use App\Models\SpecV2\HeapGrant;
use App\Services\SpecV2\Values\GroupMemberAssignment;
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
            ->with(['group', 'internalUser'])
            ->where('heap_id', $heapId)
            ->orderBy('group_id')
            ->orderBy('user_identifier')
            ->get();
    }

    public function existsForHeap(string $heapId): bool
    {
        return HeapGrant::query()->where('heap_id', $heapId)->exists();
    }

    /**
     * @param list<string> $groupIds
     */
    public function replace(string $heapId, array $groupIds): void
    {
        $this->replaceGroups($heapId, $groupIds);
    }

    /**
     * @param list<string> $groupIds
     */
    public function add(string $heapId, array $groupIds): void
    {
        $this->addGroups($heapId, $groupIds);
    }

    /**
     * @param list<string> $groupIds
     */
    public function replaceGroups(string $heapId, array $groupIds): void
    {
        HeapGrant::query()->where('heap_id', $heapId)->whereNotNull('group_id')->delete();
        $this->addGroups($heapId, $groupIds);
    }

    /**
     * @param list<string> $groupIds
     */
    public function addGroups(string $heapId, array $groupIds): void
    {
        foreach ($groupIds as $groupId) {
            HeapGrant::query()->updateOrCreate([
                'heap_id' => $heapId,
                'group_id' => $groupId,
            ], [
                'user_identifier' => null,
                'internal_user_id' => null,
            ]);
        }
    }

    /**
     * @param list<string> $groupIds
     */
    public function remove(string $heapId, array $groupIds): void
    {
        $this->removeGroups($heapId, $groupIds);
    }

    /**
     * @param list<string> $groupIds
     */
    public function removeGroups(string $heapId, array $groupIds): void
    {
        if ($groupIds === []) {
            return;
        }

        HeapGrant::query()
            ->where('heap_id', $heapId)
            ->whereIn('group_id', $groupIds)
            ->delete();
    }

    /**
     * @param list<GroupMemberAssignment> $assignments
     */
    public function replaceUsers(string $heapId, array $assignments): void
    {
        HeapGrant::query()->where('heap_id', $heapId)->whereNotNull('user_identifier')->delete();
        $this->addUsers($heapId, $assignments);
    }

    /**
     * @param list<GroupMemberAssignment> $assignments
     */
    public function addUsers(string $heapId, array $assignments): void
    {
        foreach ($assignments as $assignment) {
            HeapGrant::query()->updateOrCreate([
                'heap_id' => $heapId,
                'user_identifier' => $assignment->userIdentifier,
            ], [
                'group_id' => null,
                'internal_user_id' => $assignment->internalUserId,
            ]);
        }
    }

    /**
     * @param list<string> $userIdentifiers
     */
    public function removeUsers(string $heapId, array $userIdentifiers): void
    {
        if ($userIdentifiers === []) {
            return;
        }

        HeapGrant::query()
            ->where('heap_id', $heapId)
            ->whereIn('user_identifier', $userIdentifiers)
            ->delete();
    }

    public function clear(string $heapId): void
    {
        HeapGrant::query()->where('heap_id', $heapId)->delete();
    }
}
