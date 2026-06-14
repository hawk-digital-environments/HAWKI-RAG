<?php

declare(strict_types=1);

namespace App\Services\Dataset;

use App\Models\Dataset;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class DatasetService
{
    public function __construct(
        private DatasetRepository $datasets,
        private DatasetIdentifierFactory $identifiers,
        private DatasetPayloadBuilder $payloads,
        private ClockInterface $clock = new Clock,
    ) {}

    public function list(int $limit = 50): array
    {
        $limit = max(1, min(250, $limit));

        return $this->datasets->recentWithTasks($limit)
            ->map(fn (Dataset $dataset): array => $this->payloads->payload($dataset, includeDetails: false))
            ->all();
    }

    public function show(string $datasetId): ?array
    {
        $dataset = $this->datasets->findByDatasetId($datasetId);

        return $dataset ? $this->payloads->payload($dataset, includeDetails: true) : null;
    }

    public function create(array $input): Dataset
    {
        $datasetId = $this->identifiers->datasetId($input['dataset_id'] ?? $input['datasetId'] ?? null);
        $safe = $this->identifiers->safeName($datasetId);

        return $this->datasets->create([
            'dataset_id' => $datasetId,
            'name' => $this->identifiers->displayName($datasetId, $input['name'] ?? null),
            'description' => $this->identifiers->stringValue($input['description'] ?? null),
            'status' => $this->identifiers->stringValue($input['status'] ?? null) ?? Dataset::STATUS_ACTIVE,
            'qdrant_collection' => $this->identifiers->stringValue($input['qdrant_collection'] ?? $input['qdrantCollection'] ?? null)
                ?? $this->identifiers->qdrantCollection($safe),
            'neo4j_namespace' => $this->identifiers->stringValue($input['neo4j_namespace'] ?? $input['neo4jNamespace'] ?? null)
                ?? $this->identifiers->neo4jNamespace($safe),
            'created_at' => $this->clock->now(),
        ]);
    }

    public function ensure(string|array|null $dataset = null, array $input = []): Dataset
    {
        if (is_array($dataset)) {
            $input = $dataset;
            $dataset = null;
        }

        $datasetId = $this->identifiers->datasetId($dataset ?? $input['dataset_id'] ?? $input['datasetId'] ?? null);
        $safe = $this->identifiers->safeName($datasetId);

        return $this->datasets->firstOrCreate($datasetId, [
            'name' => $this->identifiers->displayName($datasetId, $input['name'] ?? null),
            'description' => $this->identifiers->stringValue($input['description'] ?? null),
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => $this->identifiers->stringValue($input['qdrant_collection'] ?? $input['qdrantCollection'] ?? null)
                ?? $this->identifiers->qdrantCollection($safe),
            'neo4j_namespace' => $this->identifiers->stringValue($input['neo4j_namespace'] ?? $input['neo4jNamespace'] ?? null)
                ?? $this->identifiers->neo4jNamespace($safe),
            'created_at' => $this->clock->now(),
        ]);
    }
}
