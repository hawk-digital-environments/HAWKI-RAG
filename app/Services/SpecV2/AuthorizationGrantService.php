<?php
declare(strict_types=1);

namespace App\Services\SpecV2;

use App\Models\Document;
use App\Models\SpecV2\Group;
use App\Models\SpecV2\Heap;
use App\Services\Document\DocumentRepository;
use App\Services\Authorization\AuthorizationModeService;
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
        private SpecIdentifierFactory $identifiers,
        private AuthorizationModeService $mode,
    ) {}

    public function heapGrants(string $heapId): array
    {
        $heap = $this->requireHeap($heapId);

        if (! $this->enabled()) {
            return $this->grantPayload('heap', $heap->dataset_id, []);
        }

        return $this->grantPayload('heap', $heap->dataset_id, $this->heapGrants->listForHeap($heap->dataset_id)->pluck('group_id')->all());
    }

    /**
     * @param list<mixed> $groupIds
     */
    public function replaceHeapGrants(string $heapId, array $groupIds): array
    {
        $heap = $this->requireHeap($heapId);
        if (! $this->enabled()) {
            return $this->grantPayload('heap', $heap->dataset_id, []);
        }

        $resolved = $this->validatedGroupIds($groupIds, $heap->tenant_id, $heap->dataset_id);
        $this->heapGrants->replace($heap->dataset_id, $resolved);

        return $this->heapGrants($heap->dataset_id);
    }

    /**
     * @param list<mixed> $add
     * @param list<mixed> $remove
     */
    public function updateHeapGrants(string $heapId, array $add, array $remove): array
    {
        $heap = $this->requireHeap($heapId);
        if (! $this->enabled()) {
            return $this->grantPayload('heap', $heap->dataset_id, []);
        }

        $this->heapGrants->add($heap->dataset_id, $this->validatedGroupIds($add, $heap->tenant_id, $heap->dataset_id));
        $this->heapGrants->remove($heap->dataset_id, $this->identifiers->stringList($remove));

        return $this->heapGrants($heap->dataset_id);
    }

    public function documentGrants(string $documentId): array
    {
        $document = $this->requireDocument($documentId);

        if (! $this->enabled()) {
            return $this->grantPayload('document', (string) $document->id, []);
        }

        return $this->grantPayload('document', (string) $document->id, $this->documentGrants->listForDocument((string) $document->id)->pluck('group_id')->all());
    }

    /**
     * @param list<mixed> $groupIds
     */
    public function replaceDocumentGrants(string $documentId, array $groupIds): array
    {
        $document = $this->requireDocument($documentId);
        if (! $this->enabled()) {
            return $this->grantPayload('document', (string) $document->id, []);
        }

        $this->documentGrants->replace((string) $document->id, $this->validatedGroupIds(
            $groupIds,
            (string) $document->heap?->tenant_id,
            (string) $document->id,
        ));

        return $this->documentGrants((string) $document->id);
    }

    /**
     * @param list<mixed> $add
     * @param list<mixed> $remove
     */
    public function updateDocumentGrants(string $documentId, array $add, array $remove): array
    {
        $document = $this->requireDocument($documentId);
        if (! $this->enabled()) {
            return $this->grantPayload('document', (string) $document->id, []);
        }

        $tenantId = (string) $document->heap?->tenant_id;
        $this->documentGrants->add((string) $document->id, $this->validatedGroupIds($add, $tenantId, (string) $document->id));
        $this->documentGrants->remove((string) $document->id, $this->identifiers->stringList($remove));

        return $this->documentGrants((string) $document->id);
    }

    private function requireHeap(string $heapId): Heap
    {
        $heap = $this->heaps->findById($heapId);
        if (! $heap instanceof Heap) {
            throw HeapNotFoundException::withId($heapId);
        }

        return $heap;
    }

    private function requireDocument(string $documentId): Document
    {
        $document = $this->documents->findById($documentId);
        if (! $document instanceof Document) {
            throw AuthorizationGrantException::documentNotFound($documentId);
        }

        $document->loadMissing('heap');

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
     * @param list<string> $groupIds
     * @return array<string, mixed>
     */
    private function grantPayload(string $resourceType, string $resourceId, array $groupIds): array
    {
        $groupIds = array_values(array_unique(array_filter(array_map('strval', $groupIds))));

        return [
            'resourceType' => $resourceType,
            'resourceId' => $resourceId,
            'grants' => array_map(static fn (string $groupId): array => ['groupId' => $groupId], $groupIds),
            'count' => count($groupIds),
        ];
    }

    private function enabled(): bool
    {
        return $this->mode->enabled();
    }
}
