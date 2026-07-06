<?php
declare(strict_types=1);

namespace App\Services\SpecV2;

use App\Models\SpecV2\Group;
use App\Services\Authorization\ApiActor;
use App\Services\Authorization\IdentityProvisioningService;
use App\Services\SpecV2\Exceptions\ApplicationNotFoundException;
use App\Services\SpecV2\Exceptions\GroupNotFoundException;
use App\Services\SpecV2\Payloads\PaginationPayloadBuilder;
use App\Services\SpecV2\Repositories\ApplicationRepository;
use App\Services\SpecV2\Repositories\GroupMemberRepository;
use App\Services\SpecV2\Repositories\GroupRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

#[Singleton]
readonly class GroupService
{
    public function __construct(
        private GroupRepository $groups,
        private GroupMemberRepository $members,
        private ApplicationRepository $applications,
        private IdentityProvisioningService $identityProvisioning,
        private SpecIdentifierFactory $identifiers,
        private PaginationPayloadBuilder $pagination,
    ) {}

    /**
     * @param array<string, mixed> $filters
     */
    public function list(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->groups->paginate($filters, $perPage, $page);
    }

    public function show(string $groupId): Group
    {
        $group = $this->groups->findById($groupId);
        if ($group instanceof Group) {
            $group->load('members');
        }
        if (! $group instanceof Group) {
            throw GroupNotFoundException::withId($groupId);
        }

        return $group;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, ?ApiActor $actor = null): Group
    {
        $applicationId = $this->identifiers->stringValue($input['owner_application_id'] ?? null)
            ?? $actor?->applicationId()
            ?? 'rawki-default';
        $application = $this->applications->findById($applicationId);

        if ($application === null) {
            throw ApplicationNotFoundException::withId($applicationId);
        }

        $localId = $this->identifiers->safeIdentifier((string) ($input['id'] ?? $input['name'] ?? 'group'));
        $groupId = $this->identifiers->namespacedGroupId($application->id, $localId);

        $group = $this->groups->create([
            'id' => $groupId,
            'tenant_id' => $application->tenant_id,
            'owner_application_id' => $application->id,
            'name' => $this->identifiers->displayName($localId, $input['name'] ?? null),
            'description' => $this->identifiers->stringValue($input['description'] ?? null),
            'metadata_json' => $input['metadata'] ?? null,
        ]);

        $group->load(['tenant', 'ownerApplication']);
        $group->loadCount('members');
        $group->load('members');

        return $group;
    }

    public function delete(string $groupId): void
    {
        $group = $this->groups->findById($groupId);
        if (! $group instanceof Group) {
            throw GroupNotFoundException::withId($groupId);
        }

        $this->groups->delete($group);
    }

    public function listMembers(string $groupId, int $page, int $perPage): array
    {
        $group = $this->groups->findById($groupId);
        if (! $group instanceof Group) {
            throw GroupNotFoundException::withId($groupId);
        }

        $members = $this->members->paginate($group, $perPage, $page);

        return [
            'data' => $members->getCollection()->pluck('user_identifier')->all(),
            'pagination' => $this->pagination->payload($members),
        ];
    }

    /**
     * @param list<mixed> $users
     */
    public function replaceMembers(string $groupId, array $users): array
    {
        $group = $this->groups->findById($groupId);
        if (! $group instanceof Group) {
            throw GroupNotFoundException::withId($groupId);
        }

        $this->members->replaceMembers(
            $group,
            $this->identityProvisioning->groupMemberAssignments(
                $group->tenant_id,
                $group->owner_application_id,
                $this->identifiers->stringList($users),
            ),
        );

        return $this->listMembers($groupId, 1, 250);
    }

    /**
     * @param list<mixed> $add
     * @param list<mixed> $remove
     */
    public function updateMembers(string $groupId, array $add, array $remove): array
    {
        $group = $this->groups->findById($groupId);
        if (! $group instanceof Group) {
            throw GroupNotFoundException::withId($groupId);
        }

        $this->members->addMembers(
            $group,
            $this->identityProvisioning->groupMemberAssignments(
                $group->tenant_id,
                $group->owner_application_id,
                $this->identifiers->stringList($add),
            ),
        );
        $this->members->removeMembers($group, $this->identifiers->stringList($remove));

        return $this->listMembers($groupId, 1, 250);
    }
}
