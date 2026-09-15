<?php

declare(strict_types=1);

namespace App\Services\Dataset;

use App\Models\Dataset;
use App\Services\Dataset\Exceptions\DatasetCreationConflictException;
use App\Services\Dataset\Exceptions\DatasetInactiveException;
use App\Services\Dataset\Exceptions\DatasetNotFoundException;
use App\Services\Settings\SettingsService;
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
        private DatasetStorageCleanupService $storageCleanup,
        private SettingsService $settings,
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
        $embedding = $this->embeddingRuntime();

        $dataset = $this->datasets->firstOrCreate($datasetId, [
            'dataset_id' => $datasetId,
            'name' => $this->identifiers->displayName($datasetId, $input['name'] ?? null),
            'description' => $this->identifiers->stringValue($input['description'] ?? null),
            'status' => $this->identifiers->stringValue($input['status'] ?? null) ?? Dataset::STATUS_ACTIVE,
            'qdrant_collection' => $this->identifiers->stringValue($input['qdrant_collection'] ?? $input['qdrantCollection'] ?? null)
                ?? $this->identifiers->qdrantCollection($safe),
            'neo4j_namespace' => $this->identifiers->stringValue($input['neo4j_namespace'] ?? $input['neo4jNamespace'] ?? null)
                ?? $this->identifiers->neo4jNamespace($safe),
            'embedding_provider' => $embedding['provider'],
            'embedding_model' => $embedding['model'],
            'created_at' => $this->clock->now(),
        ]);

        if (! $dataset->wasRecentlyCreated) {
            $this->ensureCompatibleCreationRequest($dataset, $input);
        }

        return $dataset;
    }

    public function requireActive(string $datasetId): Dataset
    {
        $normalizedDatasetId = trim($datasetId);
        $dataset = $this->datasets->findByDatasetId($normalizedDatasetId);
        if (! $dataset) {
            throw DatasetNotFoundException::forId($normalizedDatasetId);
        }
        if ($dataset->status !== Dataset::STATUS_ACTIVE) {
            throw DatasetInactiveException::forId($normalizedDatasetId);
        }

        return $dataset;
    }

    public function ensure(string|array|null $dataset = null, array $input = []): Dataset
    {
        if (is_array($dataset)) {
            $input = $dataset;
            $dataset = null;
        }

        $datasetId = $this->identifiers->datasetId($dataset ?? $input['dataset_id'] ?? $input['datasetId'] ?? null);
        $safe = $this->identifiers->safeName($datasetId);
        $embedding = $this->embeddingRuntime();

        return $this->datasets->firstOrCreate($datasetId, [
            'name' => $this->identifiers->displayName($datasetId, $input['name'] ?? null),
            'description' => $this->identifiers->stringValue($input['description'] ?? null),
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => $this->identifiers->stringValue($input['qdrant_collection'] ?? $input['qdrantCollection'] ?? null)
                ?? $this->identifiers->qdrantCollection($safe),
            'neo4j_namespace' => $this->identifiers->stringValue($input['neo4j_namespace'] ?? $input['neo4jNamespace'] ?? null)
                ?? $this->identifiers->neo4jNamespace($safe),
            'embedding_provider' => $embedding['provider'],
            'embedding_model' => $embedding['model'],
            'created_at' => $this->clock->now(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function delete(string $datasetId): ?array
    {
        $dataset = $this->datasets->findByDatasetId($datasetId);

        if (! $dataset) {
            return null;
        }

        $cleanup = $this->storageCleanup->deleteStorage($dataset);
        $cleanupOk = ($cleanup['qdrant']['ok'] ?? false) && ($cleanup['neo4j']['ok'] ?? false);

        return [
            ...$cleanup,
            'dataset_deleted' => $cleanupOk ? $this->datasets->delete($dataset) : false,
        ];
    }

    /**
     * @return array{provider:string,model:string}
     */
    private function embeddingRuntime(): array
    {
        $runtime = $this->settings->modelRuntime();
        $provider = trim((string) ($runtime['provider'] ?? ''));
        $embeddingModel = trim((string) ($runtime['embedding_model'] ?? ''));

        return [
            'provider' => $provider !== '' ? $provider : 'ollama',
            'model' => $embeddingModel !== '' ? $embeddingModel : 'bge-m3',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function ensureCompatibleCreationRequest(Dataset $dataset, array $input): void
    {
        $requestedValues = [
            'name' => $this->requestedString($input, 'name'),
            'description' => $this->requestedString($input, 'description'),
            'status' => $this->requestedString($input, 'status'),
            'qdrant_collection' => $this->requestedString($input, 'qdrant_collection', 'qdrantCollection'),
            'neo4j_namespace' => $this->requestedString($input, 'neo4j_namespace', 'neo4jNamespace'),
        ];

        foreach ($requestedValues as $field => $requestedValue) {
            if ($requestedValue === null) {
                continue;
            }

            if (! hash_equals((string) $dataset->getAttribute($field), $requestedValue)) {
                throw DatasetCreationConflictException::forField(
                    (string) $dataset->dataset_id,
                    $field,
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function requestedString(array $input, string $key, ?string $alias = null): ?string
    {
        foreach (array_filter([$key, $alias]) as $candidate) {
            if (array_key_exists($candidate, $input)) {
                return $this->identifiers->stringValue($input[$candidate]);
            }
        }

        return null;
    }
}
