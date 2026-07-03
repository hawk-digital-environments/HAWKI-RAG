<?php
declare(strict_types=1);

namespace App\Services\SpecV2;

use App\Models\Dataset;
use App\Models\User;
use App\Models\SpecV2\Heap;
use App\Services\Authorization\IdentityProvisioningService;
use App\Services\Dataset\DatasetIdentifierFactory;
use App\Services\Dataset\DatasetService;
use App\Services\SpecV2\Exceptions\ApplicationNotFoundException;
use App\Services\SpecV2\Exceptions\HeapNotFoundException;
use App\Services\SpecV2\Payloads\HeapPayloadBuilder;
use App\Services\SpecV2\Payloads\PaginationPayloadBuilder;
use App\Services\SpecV2\Repositories\ApplicationRepository;
use App\Services\SpecV2\Repositories\HeapRepository;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class HeapService
{
    public function __construct(
        private HeapRepository $heaps,
        private ApplicationRepository $applications,
        private IdentityProvisioningService $identityProvisioning,
        private DatasetIdentifierFactory $datasetIdentifiers,
        private SpecIdentifierFactory $identifiers,
        private DatasetService $datasets,
        private HeapPayloadBuilder $payloads,
        private PaginationPayloadBuilder $pagination,
        private ClockInterface $clock = new Clock,
    ) {}

    /**
     * @param array<string, mixed> $filters
     */
    public function list(array $filters, int $page, int $perPage): array
    {
        $heaps = $this->heaps->paginate($filters, $perPage, $page);

        return [
            'data' => $heaps->getCollection()->map(fn (Heap $heap): array => $this->payloads->payload($heap))->all(),
            'pagination' => $this->pagination->payload($heaps),
        ];
    }

    public function show(string $heapId): array
    {
        $heap = $this->heaps->findById($heapId);
        if (! $heap instanceof Heap) {
            throw HeapNotFoundException::withId($heapId);
        }

        return $this->payloads->payload($heap);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, ?User $actor = null): array
    {
        $applicationId = $this->identifiers->stringValue($input['owner_application_id'] ?? null)
            ?? $this->identityProvisioning->actorForUser($actor)?->application_id
            ?? 'rawki-default';
        $application = $this->applications->findById($applicationId);

        if ($application === null) {
            throw ApplicationNotFoundException::withId($applicationId);
        }

        $heapId = $this->datasetIdentifiers->datasetId(
            $input['id'] ?? $input['heap_id'] ?? $this->datasetIdentifiers->safeName((string) ($input['name'] ?? 'heap'))
        );
        $safe = $this->datasetIdentifiers->safeName($heapId);

        $heap = $this->heaps->create([
            'dataset_id' => $heapId,
            'tenant_id' => $application->tenant_id,
            'owner_application_id' => $application->id,
            'name' => $this->identifiers->displayName($heapId, $input['name'] ?? null),
            'description' => $this->identifiers->stringValue($input['description'] ?? null),
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => $this->visibility($input['visibility'] ?? null),
            'protected' => (bool) ($input['protected'] ?? false),
            'metadata_json' => $input['metadata'] ?? null,
            'qdrant_collection' => $this->datasetIdentifiers->qdrantCollection($safe),
            'neo4j_namespace' => $this->datasetIdentifiers->neo4jNamespace($safe),
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);

        $heap->load(['tenant', 'ownerApplication']);
        $heap->loadCount('documents');

        return $this->payloads->payload($heap);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(string $heapId, array $input): array
    {
        $heap = $this->heaps->findById($heapId);
        if (! $heap instanceof Heap) {
            throw HeapNotFoundException::withId($heapId);
        }

        if (array_key_exists('name', $input)) {
            $heap->name = $this->identifiers->displayName($heap->dataset_id, $input['name']);
        }

        if (array_key_exists('description', $input)) {
            $heap->description = $this->identifiers->stringValue($input['description']);
        }

        if (array_key_exists('visibility', $input)) {
            $heap->visibility = $this->visibility($input['visibility']);
        }

        if (array_key_exists('protected', $input)) {
            $heap->protected = (bool) $input['protected'];
        }

        if (array_key_exists('metadata', $input)) {
            $heap->metadata_json = $input['metadata'];
        }

        $heap->updated_at = $this->clock->now();
        $this->heaps->save($heap);
        $heap->load(['tenant', 'ownerApplication']);
        $heap->loadCount('documents');

        return $this->payloads->payload($heap);
    }

    public function delete(string $heapId): array
    {
        $deleted = $this->datasets->delete($heapId);
        if ($deleted === null) {
            throw HeapNotFoundException::withId($heapId);
        }

        return $deleted;
    }

    private function visibility(mixed $value): string
    {
        $visibility = $this->identifiers->stringValue($value);

        return in_array($visibility, [Heap::VISIBILITY_DISCOVERABLE, Heap::VISIBILITY_HIDDEN], true)
            ? $visibility
            : Heap::VISIBILITY_DISCOVERABLE;
    }
}
