<?php
declare(strict_types=1);

namespace App\Services\SpecV2;

use App\Models\SpecV2\Heap;
use App\Services\Authorization\ApiActor;
use App\Services\Authorization\ApiActorScopeService;
use App\Services\Authorization\AuthorizationModeService;
use App\Services\SpecV2\Events\HeapSearchPayloadChanged;
use App\Services\Heap\HeapIdentifierFactory;
use App\Services\SpecV2\Exceptions\AccessDeniedException;
use App\Services\SpecV2\Exceptions\ApplicationNotFoundException;
use App\Services\SpecV2\Exceptions\HeapNotFoundException;
use App\Services\SpecV2\Repositories\ApplicationRepository;
use App\Services\SpecV2\Repositories\HeapRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class HeapService
{
    public function __construct(
        private HeapRepository $heaps,
        private ApplicationRepository $applications,
        private HeapIdentifierFactory $heapIdentifiers,
        private SpecIdentifierFactory $identifiers,
        private HeapDeletionService $deletions,
        private ApiActorScopeService $actors,
        private AuthorizationModeService $mode,
        private ClockInterface $clock = new Clock,
    ) {}

    /**
     * @param array<string, mixed> $filters
     */
    public function list(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $filters = $this->mode->sanitizeHeapFilters($filters);

        return $this->heaps->paginate([
            ...$filters,
            ...$this->actors->currentHeapFilters(),
        ], $perPage, $page);
    }

    public function show(string $heapId): Heap
    {
        $heap = $this->heaps->findById($heapId);
        if (! $heap instanceof Heap) {
            throw HeapNotFoundException::withId($heapId);
        }

        if (! $this->actors->currentCanReadHeap($heap)) {
            throw AccessDeniedException::forAction('read', 'heap', $heapId);
        }

        return $heap;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, ?ApiActor $actor = null): Heap
    {
        $input = $this->mode->sanitizeHeapInput($input);
        $applicationId = $this->identifiers->stringValue($input['owner_application_id'] ?? null)
            ?? $actor?->applicationId()
            ?? 'rawki-default';
        $application = $this->applications->findById($applicationId);

        if ($application === null) {
            throw ApplicationNotFoundException::withId($applicationId);
        }

        $heapId = $this->heapIdentifiers->heapId(
            $input['id'] ?? $input['heap_id'] ?? $this->heapIdentifiers->safeName((string) ($input['name'] ?? 'heap'))
        );
        $safe = $this->heapIdentifiers->safeName($heapId);

        $heap = $this->heaps->create([
            'heap_id' => $heapId,
            'tenant_id' => $application->tenant_id,
            'owner_application_id' => $application->id,
            'name' => $this->identifiers->displayName($heapId, $input['name'] ?? null),
            'description' => $this->identifiers->stringValue($input['description'] ?? null),
            'status' => Heap::STATUS_ACTIVE,
            'visibility' => $this->visibility($input['visibility'] ?? null),
            'protected' => false,
            'metadata_json' => $input['metadata'] ?? null,
            'qdrant_collection' => $this->heapIdentifiers->qdrantCollection($safe),
            'neo4j_namespace' => $this->heapIdentifiers->neo4jNamespace($safe),
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);

        $heap->load(['tenant', 'ownerApplication']);
        $heap->loadCount('documents');

        return $heap;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(string $heapId, array $input): Heap
    {
        $input = $this->mode->sanitizeHeapInput($input);
        $heap = $this->heaps->findById($heapId);
        if (! $heap instanceof Heap) {
            throw HeapNotFoundException::withId($heapId);
        }

        if (! $this->actors->currentCanReadHeap($heap)) {
            throw HeapNotFoundException::withId($heapId);
        }

        $previousMetadataKeys = array_keys(is_array($heap->metadata_json) ? $heap->metadata_json : []);

        if (array_key_exists('name', $input)) {
            $heap->name = $this->identifiers->displayName($heap->heapId(), $input['name']);
        }

        if (array_key_exists('description', $input)) {
            $heap->description = $this->identifiers->stringValue($input['description']);
        }

        if (array_key_exists('visibility', $input)) {
            $heap->visibility = $this->visibility($input['visibility']);
        }

        if (array_key_exists('metadata', $input)) {
            $heap->metadata_json = $input['metadata'];
        }

        $heap->updated_at = $this->clock->now();
        $this->heaps->save($heap);
        $heap->load(['tenant', 'ownerApplication']);
        $heap->loadCount('documents');

        if ($heap->wasChanged(['metadata_json', 'visibility'])) {
            event(new HeapSearchPayloadChanged($heap->heapId(), $previousMetadataKeys));
        }

        return $heap;
    }

    public function delete(string $heapId): array
    {
        $heap = $this->heaps->findById($heapId);
        if (! $heap instanceof Heap) {
            throw HeapNotFoundException::withId($heapId);
        }

        if (! $this->actors->currentCanReadHeap($heap)) {
            throw HeapNotFoundException::withId($heapId);
        }

        return $this->deletions->delete($heap);
    }

    private function visibility(mixed $value): string
    {
        $visibility = $this->identifiers->stringValue($value);

        return in_array($visibility, [Heap::VISIBILITY_DISCOVERABLE, Heap::VISIBILITY_HIDDEN], true)
            ? $visibility
            : Heap::VISIBILITY_DISCOVERABLE;
    }
}
