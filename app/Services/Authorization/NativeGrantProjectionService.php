<?php
declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\SpecV2\Group;
use App\Services\Authorization\Values\LmsDocumentRelation;
use App\Services\Authorization\Values\LmsMembership;
use App\Services\Document\DocumentRepository;
use App\Services\SpecV2\Repositories\DocumentGrantRepository;
use App\Services\SpecV2\Repositories\GroupMemberRepository;
use App\Services\SpecV2\Repositories\GroupRepository;
use App\Services\SpecV2\SpecIdentifierFactory;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class NativeGrantProjectionService
{
    public function __construct(
        private DocumentRepository $documents,
        private GroupRepository $groups,
        private GroupMemberRepository $members,
        private DocumentGrantRepository $documentGrants,
        private IdentityProvisioningService $identities,
        private SpecIdentifierFactory $identifiers,
    ) {}

    /**
     * @param iterable<int, LmsMembership> $memberships
     * @param iterable<int, LmsDocumentRelation> $documentRelations
     * @return array{groups_created:int,document_grants_created:int,group_members_upserted:int}
     */
    public function project(iterable $memberships, iterable $documentRelations): array
    {
        $createdGroups = 0;
        $createdDocumentGrants = 0;
        $memberUpserts = 0;
        $groupsByCourse = [];

        foreach ($documentRelations as $relation) {
            $document = $this->documents->findById($relation->documentId);
            if ($document === null) {
                continue;
            }

            $document->loadMissing('heap');
            $heap = $document->heap;
            if ($heap === null) {
                continue;
            }

            $group = $this->groups->findConnectorProjectionGroup(
                (string) $heap->tenant_id,
                (string) $heap->owner_application_id,
                $relation->provider,
                $relation->courseId,
            );

            if (! $group instanceof Group) {
                $group = $this->groups->create([
                    'id' => $this->identifiers->namespacedGroupId(
                        (string) $heap->owner_application_id,
                        $this->identifiers->safeIdentifier('connector-'.$relation->provider.'-'.$relation->courseId),
                    ),
                    'tenant_id' => (string) $heap->tenant_id,
                    'owner_application_id' => (string) $heap->owner_application_id,
                    'name' => $relation->provider.' '.$relation->courseId,
                    'description' => 'Connector-projected authorization group',
                    'metadata_json' => [
                        'projection' => [
                            'source' => 'permission-sync',
                            'provider' => $relation->provider,
                            'course_id' => $relation->courseId,
                        ],
                    ],
                ]);
                $createdGroups++;
            }

            $before = $this->documentGrants->listForDocument((string) $document->id)->pluck('group_id')->all();
            $this->documentGrants->add((string) $document->id, [$group->id]);
            $after = $this->documentGrants->listForDocument((string) $document->id)->pluck('group_id')->all();
            if (! in_array($group->id, $before, true) && in_array($group->id, $after, true)) {
                $createdDocumentGrants++;
            }

            $groupsByCourse[$this->courseKey($relation->provider, $relation->courseId)][$group->id] = $group;
        }

        foreach ($memberships as $membership) {
            $groups = $groupsByCourse[$this->courseKey($membership->provider, $membership->courseId)] ?? [];

            if ($groups === []) {
                $groups = $this->groups->findConnectorProjectionGroups($membership->provider, $membership->courseId)
                    ->keyBy('id')
                    ->all();
            }

            foreach ($groups as $group) {
                if (! $group instanceof Group) {
                    continue;
                }

                $assignments = $this->identities->connectorMemberAssignments(
                    (string) $group->tenant_id,
                    (string) $group->owner_application_id,
                    $membership->provider,
                    [$membership->externalUserId],
                );

                $this->members->addMembers($group, $assignments);
                $memberUpserts += count($assignments);
            }
        }

        return [
            'groups_created' => $createdGroups,
            'document_grants_created' => $createdDocumentGrants,
            'group_members_upserted' => $memberUpserts,
        ];
    }

    private function courseKey(string $provider, string $courseId): string
    {
        return $provider.'|'.$courseId;
    }
}
