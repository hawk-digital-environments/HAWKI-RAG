<?php
declare(strict_types=1);

namespace App\Services\Heap;

use App\Models\Dataset;
use App\Services\Authorization\ApiActorScopeService;
use App\Services\Dataset\DatasetPayloadBuilder;
use App\Services\Dataset\DatasetStorageCleanupService;
use App\Services\Heap\Repositories\HeapCatalogRepository;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class HeapCatalogService
{
    public function __construct(
        private HeapCatalogRepository $heaps,
        private HeapIdentifierFactory $identifiers,
        private DatasetPayloadBuilder $payloads,
        private DatasetStorageCleanupService $storageCleanup,
        private ApiActorScopeService $actors,
        private ClockInterface $clock = new Clock,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $limit = 50): array
    {
        $limit = max(1, min(250, $limit));

        return $this->heaps->recentWithTasks($limit)
            ->filter(fn (Dataset $heap): bool => $this->actors->currentCanReadDataset($heap))
            ->map(fn (Dataset $heap): array => $this->payloads->payload($heap, includeDetails: false))
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function show(string $heapId): ?array
    {
        $heap = $this->heaps->findByHeapId($heapId);
        if (! $heap instanceof Dataset || ! $this->actors->currentCanReadDataset($heap)) {
            return null;
        }

        return $this->payloads->payload($heap, includeDetails: true);
    }

    public function create(array $input): Dataset
    {
        $heapId = $this->identifiers->heapId($input['heap_id'] ?? $input['heapId'] ?? $input['dataset_id'] ?? $input['datasetId'] ?? null);
        $safe = $this->identifiers->safeName($heapId);
        $defaults = $this->actors->currentOwnershipDefaults();

        return $this->heaps->create([
            'dataset_id' => $heapId,
            'tenant_id' => $this->identifiers->stringValue($input['tenant_id'] ?? null) ?? $defaults['tenant_id'],
            'owner_application_id' => $this->identifiers->stringValue($input['owner_application_id'] ?? null) ?? $defaults['owner_application_id'],
            'name' => $this->identifiers->displayName($heapId, $input['name'] ?? null),
            'description' => $this->identifiers->stringValue($input['description'] ?? null),
            'status' => $this->identifiers->stringValue($input['status'] ?? null) ?? Dataset::STATUS_ACTIVE,
            'visibility' => $this->identifiers->stringValue($input['visibility'] ?? null) ?? Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => (bool) ($input['protected'] ?? false),
            'metadata_json' => $input['metadata'] ?? $input['metadata_json'] ?? null,
            'qdrant_collection' => $this->identifiers->stringValue($input['qdrant_collection'] ?? $input['qdrantCollection'] ?? null)
                ?? $this->identifiers->qdrantCollection($safe),
            'neo4j_namespace' => $this->identifiers->stringValue($input['neo4j_namespace'] ?? $input['neo4jNamespace'] ?? null)
                ?? $this->identifiers->neo4jNamespace($safe),
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);
    }

    public function ensure(string|array|null $heap = null, array $input = []): Dataset
    {
        if (is_array($heap)) {
            $input = $heap;
            $heap = null;
        }

        $heapId = $this->identifiers->heapId($heap ?? $input['heap_id'] ?? $input['heapId'] ?? $input['dataset_id'] ?? $input['datasetId'] ?? null);
        $safe = $this->identifiers->safeName($heapId);
        $defaults = $this->actors->currentOwnershipDefaults();

        return $this->heaps->firstOrCreate($heapId, [
            'name' => $this->identifiers->displayName($heapId, $input['name'] ?? null),
            'description' => $this->identifiers->stringValue($input['description'] ?? null),
            'status' => Dataset::STATUS_ACTIVE,
            'tenant_id' => $this->identifiers->stringValue($input['tenant_id'] ?? null) ?? $defaults['tenant_id'],
            'owner_application_id' => $this->identifiers->stringValue($input['owner_application_id'] ?? null) ?? $defaults['owner_application_id'],
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => $input['metadata'] ?? $input['metadata_json'] ?? null,
            'qdrant_collection' => $this->identifiers->stringValue($input['qdrant_collection'] ?? $input['qdrantCollection'] ?? null)
                ?? $this->identifiers->qdrantCollection($safe),
            'neo4j_namespace' => $this->identifiers->stringValue($input['neo4j_namespace'] ?? $input['neo4jNamespace'] ?? null)
                ?? $this->identifiers->neo4jNamespace($safe),
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function delete(string $heapId): ?array
    {
        $heap = $this->heaps->findByHeapId($heapId);

        if (! $heap instanceof Dataset || ! $this->actors->currentCanReadDataset($heap)) {
            return null;
        }

        $cleanup = $this->storageCleanup->deleteStorage($heap);
        $cleanupOk = ($cleanup['qdrant']['ok'] ?? false) && ($cleanup['neo4j']['ok'] ?? false);

        return [
            ...$cleanup,
            'datasetDeleted' => $cleanupOk ? $this->heaps->delete($heap) : false,
        ];
    }
}
