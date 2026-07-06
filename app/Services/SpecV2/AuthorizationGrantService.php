<?php
declare(strict_types=1);

namespace App\Services\SpecV2;

use App\Models\Document;
use App\Models\SpecV2\Application;
use App\Models\SpecV2\Group;
use App\Models\SpecV2\Heap;
use App\Services\Authorization\ApiActor;
use App\Services\Authorization\ApiActorScopeService;
use App\Services\Document\DocumentRepository;
use App\Services\Authorization\AuthorizationModeService;
use App\Services\Authorization\IdentityProvisioningService;
use App\Services\Authorization\Repositories\GrantAccessRepository;
use App\Services\Authorization\Repositories\UserIdentityRepository;
use App\Services\SpecV2\Exceptions\AuthorizationGrantException;
use App\Services\SpecV2\Exceptions\GroupNotFoundException;
use App\Services\SpecV2\Exceptions\HeapNotFoundException;
use App\Services\SpecV2\Repositories\DocumentGrantRepository;
use App\Services\SpecV2\Repositories\GroupRepository;
use App\Services\SpecV2\Repositories\HeapGrantRepository;
use App\Services\SpecV2\Repositories\HeapRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class AuthorizationGrantService
{
    public function __construct(
        private HeapRepository $heaps,
        private GroupRepository $groups,
        private HeapGrantRepository $heapGrants,
        private DocumentGrantRepository $documentGrants,
        private DocumentRepository $documents,
        private GrantAccessRepository $grantAccess,
        private UserIdentityRepository $identities,
        private IdentityProvisioningService $identityProvisioning,
        private ApiActorScopeService $actors,
        private SpecIdentifierFactory $identifiers,
        private AuthorizationModeService $mode,
    ) {}

    public function heapGrants(string $heapId): array
    {
        $heap = $this->readableHeap($heapId);

        if (! $this->enabled()) {
            return $this->grantPayload('heap', $heap->dataset_id, [], [], (bool) $heap->protected);
        }

        return $this->grantPayload(
            'heap',
            $heap->dataset_id,
            $this->grantedUsers($this->heapGrants->listForHeap($heap->dataset_id)),
            $this->grantedGroups($this->heapGrants->listForHeap($heap->dataset_id)),
            (bool) $heap->protected,
        );
    }

    /**
     * @param list<mixed> $users
     * @param list<mixed> $groups
     */
    public function replaceHeapGrants(string $heapId, array $users, array $groups): array
    {
        $heap = $this->ownedHeap($heapId);
        if (! $this->enabled()) {
            return $this->grantPayload('heap', $heap->dataset_id, [], [], (bool) $heap->protected);
        }

        $heap->protected = true;
        $this->heaps->save($heap);
        $this->heapGrants->replaceUsers(
            $heap->dataset_id,
            $this->identityProvisioning->userAssignments($heap->tenant_id, $heap->owner_application_id, $this->identifiers->stringList($users)),
        );
        $this->heapGrants->replaceGroups($heap->dataset_id, $this->validatedGroupIds($groups, $heap->tenant_id, $heap->dataset_id));

        return $this->heapGrants($heap->dataset_id);
    }

    /**
     * @param list<mixed> $addUsers
     * @param list<mixed> $removeUsers
     * @param list<mixed> $addGroups
     * @param list<mixed> $removeGroups
     */
    public function updateHeapGrants(string $heapId, array $addUsers, array $removeUsers, array $addGroups, array $removeGroups): array
    {
        $heap = $this->ownedHeap($heapId);
        if (! $this->enabled()) {
            return $this->grantPayload('heap', $heap->dataset_id, [], [], (bool) $heap->protected);
        }

        $heap->protected = true;
        $this->heaps->save($heap);
        $this->heapGrants->addUsers(
            $heap->dataset_id,
            $this->identityProvisioning->userAssignments($heap->tenant_id, $heap->owner_application_id, $this->identifiers->stringList($addUsers)),
        );
        $this->heapGrants->removeUsers($heap->dataset_id, $this->identifiers->stringList($removeUsers));
        $this->heapGrants->addGroups($heap->dataset_id, $this->validatedGroupIds($addGroups, $heap->tenant_id, $heap->dataset_id));
        $this->heapGrants->removeGroups($heap->dataset_id, $this->identifiers->stringList($removeGroups));

        return $this->heapGrants($heap->dataset_id);
    }

    public function documentGrants(string $documentId): array
    {
        $document = $this->readableDocument($documentId);

        if (! $this->enabled()) {
            return $this->grantPayload('document', (string) $document->id, [], []);
        }

        return $this->grantPayload(
            'document',
            (string) $document->id,
            $this->grantedUsers($this->documentGrants->listForDocument((string) $document->id)),
            $this->grantedGroups($this->documentGrants->listForDocument((string) $document->id)),
        );
    }

    /**
     * @param list<mixed> $users
     * @param list<mixed> $groups
     */
    public function replaceDocumentGrants(string $documentId, array $users, array $groups): array
    {
        $document = $this->ownedDocument($documentId);
        if (! $this->enabled()) {
            return $this->grantPayload('document', (string) $document->id, [], []);
        }

        $this->documentGrants->replaceUsers(
            (string) $document->id,
            $this->identityProvisioning->userAssignments(
                (string) $document->heap?->tenant_id,
                (string) $document->heap?->owner_application_id,
                $this->identifiers->stringList($users),
            ),
        );
        $this->documentGrants->replaceGroups((string) $document->id, $this->validatedGroupIds(
            $groups,
            (string) $document->heap?->tenant_id,
            (string) $document->id,
        ));

        return $this->documentGrants((string) $document->id);
    }

    /**
     * @param list<mixed> $addUsers
     * @param list<mixed> $removeUsers
     * @param list<mixed> $addGroups
     * @param list<mixed> $removeGroups
     */
    public function updateDocumentGrants(string $documentId, array $addUsers, array $removeUsers, array $addGroups, array $removeGroups): array
    {
        $document = $this->ownedDocument($documentId);
        if (! $this->enabled()) {
            return $this->grantPayload('document', (string) $document->id, [], []);
        }

        $tenantId = (string) $document->heap?->tenant_id;
        $this->documentGrants->addUsers(
            (string) $document->id,
            $this->identityProvisioning->userAssignments(
                $tenantId,
                (string) $document->heap?->owner_application_id,
                $this->identifiers->stringList($addUsers),
            ),
        );
        $this->documentGrants->removeUsers((string) $document->id, $this->identifiers->stringList($removeUsers));
        $this->documentGrants->addGroups((string) $document->id, $this->validatedGroupIds($addGroups, $tenantId, (string) $document->id));
        $this->documentGrants->removeGroups((string) $document->id, $this->identifiers->stringList($removeGroups));

        return $this->documentGrants((string) $document->id);
    }

    public function deleteHeapGrants(string $heapId): void
    {
        $heap = $this->ownedHeap($heapId);
        if (! $this->enabled()) {
            return;
        }

        $this->heapGrants->clear($heap->dataset_id);
        $heap->protected = false;
        $this->heaps->save($heap);
    }

    public function deleteDocumentGrants(string $documentId): void
    {
        $document = $this->ownedDocument($documentId);
        if (! $this->enabled()) {
            return;
        }

        $this->documentGrants->clear((string) $document->id);
    }

    public function checkAccess(string $userIdentifier, ?string $heapId, ?string $documentId): array
    {
        $heapId = $this->identifiers->stringValue($heapId);
        $documentId = $this->identifiers->stringValue($documentId);

        if (($heapId === null && $documentId === null) || ($heapId !== null && $documentId !== null)) {
            throw AuthorizationGrantException::invalidPermissionCheckTarget();
        }

        if ($heapId !== null) {
            $heap = $this->readableHeap($heapId);

            return ['permitted' => $this->permittedHeap($heap, $userIdentifier)];
        }

        $document = $this->readableDocument((string) $documentId);

        return ['permitted' => $this->permittedDocument($document, $userIdentifier)];
    }

    /**
     * @return array{data: list<string>, pagination: array{page: int, per_page: int, total: int}}
     */
    public function heapsByIdentifier(string $identifier, int $page, int $perPage): array
    {
        if (! $this->enabled()) {
            return [
                'data' => [],
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => 0,
                ],
            ];
        }

        $internalUserIds = $this->resolvedInternalUserIds($identifier);
        $heapIds = [];

        foreach ($this->grantAccess->accessibleHeapIdsForInternalUsers($internalUserIds) as $heapId) {
            $heap = $this->heaps->findById($heapId);
            if ($heap instanceof Heap && $this->currentCanScopeHeap($heap)) {
                $heapIds[] = $heap->dataset_id;
            }
        }

        $heapIds = array_values(array_unique($heapIds));
        sort($heapIds);
        $total = count($heapIds);

        return [
            'data' => array_values(array_slice($heapIds, max(0, ($page - 1) * $perPage), $perPage)),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    private function readableHeap(string $heapId): Heap
    {
        $heap = $this->heaps->findById($heapId);
        if (! $heap instanceof Heap || ! $this->currentCanScopeHeap($heap)) {
            throw HeapNotFoundException::withId($heapId);
        }

        return $heap;
    }

    private function ownedHeap(string $heapId): Heap
    {
        $heap = $this->readableHeap($heapId);
        if (! $this->currentOwnsHeap($heap)) {
            throw HeapNotFoundException::withId($heapId);
        }

        return $heap;
    }

    private function readableDocument(string $documentId): Document
    {
        $document = $this->documents->findById($documentId);
        if (! $document instanceof Document) {
            throw AuthorizationGrantException::documentNotFound($documentId);
        }

        $document->loadMissing('heap');

        if (! $document->heap instanceof Heap || ! $this->currentCanScopeHeap($document->heap)) {
            throw AuthorizationGrantException::documentNotFound($documentId);
        }

        return $document;
    }

    private function ownedDocument(string $documentId): Document
    {
        $document = $this->readableDocument($documentId);
        if (! $document->heap instanceof Heap || ! $this->currentOwnsHeap($document->heap)) {
            throw AuthorizationGrantException::documentNotFound($documentId);
        }

        return $document;
    }

    /**
     * @param list<mixed> $groupIds
     * @return list<string>
     */
    private function validatedGroupIds(array $groupIds, string $tenantId, string $resourceId): array
    {
        $resolved = $this->identifiers->stringList($groupIds);

        foreach ($resolved as $groupId) {
            $group = $this->groups->findById($groupId);
            if (! $group instanceof Group) {
                throw GroupNotFoundException::withId($groupId);
            }

            if ((string) $group->tenant_id !== $tenantId) {
                throw AuthorizationGrantException::groupsMustShareTenant($resourceId, $tenantId);
            }
        }

        return $resolved;
    }

    /**
     * @param \Illuminate\Support\Collection<int, mixed> $grants
     * @return list<string>
     */
    private function grantedUsers(\Illuminate\Support\Collection $grants): array
    {
        return array_values(array_unique(array_filter($grants->pluck('user_identifier')->map(
            fn (mixed $userIdentifier): ?string => $this->identifiers->stringValue($userIdentifier),
        )->all())));
    }

    /**
     * @param \Illuminate\Support\Collection<int, mixed> $grants
     * @return list<string>
     */
    private function grantedGroups(\Illuminate\Support\Collection $grants): array
    {
        return array_values(array_unique(array_filter($grants->pluck('group_id')->map(
            fn (mixed $groupId): ?string => $this->identifiers->stringValue($groupId),
        )->all())));
    }

    /**
     * @param list<string> $users
     * @param list<string> $groups
     * @return array<string, mixed>
     */
    private function grantPayload(string $resourceType, string $resourceId, array $users, array $groups, ?bool $protected = null): array
    {
        $users = array_values(array_unique(array_filter(array_map('strval', $users))));
        $groups = array_values(array_unique(array_filter(array_map('strval', $groups))));

        $payload = [
            'resourceType' => $resourceType,
            'resourceId' => $resourceId,
            'users' => $users,
            'groups' => $groups,
            'grants' => [
                'users' => $users,
                'groups' => $groups,
            ],
            'count' => count($users) + count($groups),
        ];

        if ($protected !== null) {
            $payload['protected'] = $protected;
        }

        return $payload;
    }

    private function enabled(): bool
    {
        return $this->mode->enabled();
    }

    /**
     * @return list<string>
     */
    private function resolvedInternalUserIds(string $identifier): array
    {
        $actor = $this->actors->currentActor();
        if (! $actor instanceof ApiActor) {
            return [];
        }

        $tenantIds = $actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED)
            ? null
            : [$actor->tenantId()];

        return $this->identities->findAllByIdentifiers([$identifier], $tenantIds)
            ->pluck('internal_user_id')
            ->filter(fn (mixed $internalUserId): bool => is_string($internalUserId) && trim($internalUserId) !== '')
            ->map(fn (string $internalUserId): string => trim($internalUserId))
            ->unique()
            ->values()
            ->all();
    }

    private function permittedHeap(Heap $heap, string $userIdentifier): bool
    {
        if (! $this->currentCanScopeHeap($heap)) {
            return false;
        }

        $actor = $this->actors->currentActor();
        if (! $this->enabled() || ! (bool) $heap->protected) {
            return true;
        }

        if ($actor instanceof ApiActor && $actor->hasApplicationPermission(Application::PERMISSION_READS_PROTECTED)) {
            return true;
        }

        return $this->grantAccess->canViewHeap($heap->dataset_id, $this->resolvedInternalUserIds($userIdentifier));
    }

    private function permittedDocument(Document $document, string $userIdentifier): bool
    {
        if (! $document->heap instanceof Heap || ! $this->currentCanScopeHeap($document->heap)) {
            return false;
        }

        $actor = $this->actors->currentActor();
        if (! $this->enabled() || ! (bool) $document->heap->protected) {
            return true;
        }

        if ($actor instanceof ApiActor && $actor->hasApplicationPermission(Application::PERMISSION_READS_PROTECTED)) {
            return true;
        }

        return $this->grantAccess->canViewDocument((string) $document->id, $this->resolvedInternalUserIds($userIdentifier));
    }

    private function currentCanScopeHeap(Heap $heap): bool
    {
        $actor = $this->actors->currentActor();
        if (! $actor instanceof ApiActor) {
            return false;
        }

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED)) {
            return true;
        }

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_ALL_APPS)) {
            return (string) $heap->tenant_id === $actor->tenantId();
        }

        return (string) $heap->owner_application_id === $actor->applicationId();
    }

    private function currentOwnsHeap(Heap $heap): bool
    {
        $actor = $this->actors->currentActor();

        return $actor instanceof ApiActor && (string) $heap->owner_application_id === $actor->applicationId();
    }
}
