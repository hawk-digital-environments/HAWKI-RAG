<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Repositories;

use App\Models\SpecV2\Group;
use App\Models\SpecV2\GroupMember;
use App\Services\SpecV2\Values\GroupMemberAssignment;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

#[Singleton]
readonly class GroupMemberRepository
{
    public function paginate(Group $group, int $perPage, int $page): LengthAwarePaginator
    {
        return GroupMember::query()
            ->where('group_id', $group->id)
            ->orderBy('user_identifier')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param list<GroupMemberAssignment> $assignments
     */
    public function replaceMembers(Group $group, array $assignments): void
    {
        DB::transaction(function () use ($group, $assignments): void {
            GroupMember::query()->where('group_id', $group->id)->delete();
            $this->addMembers($group, $assignments);
        });
    }

    /**
     * @param list<GroupMemberAssignment> $assignments
     */
    public function addMembers(Group $group, array $assignments): void
    {
        foreach ($assignments as $assignment) {
            GroupMember::query()->updateOrCreate(
                [
                    'group_id' => $group->id,
                    'user_identifier' => $assignment->userIdentifier,
                ],
                [
                    'internal_user_id' => $assignment->internalUserId,
                ],
            );
        }
    }

    /**
     * @param list<string> $userIdentifiers
     */
    public function removeMembers(Group $group, array $userIdentifiers): void
    {
        if ($userIdentifiers === []) {
            return;
        }

        GroupMember::query()
            ->where('group_id', $group->id)
            ->whereIn('user_identifier', $userIdentifiers)
            ->delete();
    }
}
