<?php

declare(strict_types=1);

namespace App\Services\Dataset;

use App\Models\Dataset;
use App\Services\Authorization\DatasetGrantTokenService;
use App\Services\Dataset\Exceptions\DatasetCreationConflictException;
use App\Services\Dataset\Exceptions\DatasetInactiveException;
use App\Services\Dataset\Exceptions\DatasetNotFoundException;
use App\Services\Dataset\Values\DatasetCreationResult;
use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\ManagedDocumentOutputDeletionService;
use App\Services\Document\Repositories\ManagedDocumentDeletionRepository;
use App\Services\Document\Repositories\ManagedDocumentRepository;
use App\Services\Settings\SettingsService;
use App\Services\TextIngestion\Values\TextIngestionMode;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
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
        private DatasetGrantTokenService $grantTokens,
        private DatabaseManager $database,
        private ManagedDocumentRepository $managedDocuments,
        private ManagedDocumentOutputDeletionService $managedDeletion,
        private ManagedDocumentDeletionRepository $deletionOperations,
        private LoggerInterface $logger,
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

        return $dataset?->status === Dataset::STATUS_ACTIVE ? $this->payloads->payload($dataset, includeDetails: true) : null;
    }

    public function create(array $input): DatasetCreationResult
    {
        return $this->database->transaction(function () use ($input): DatasetCreationResult {
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

                // A compatible replay never re-issues the creator's bootstrap
                // token: ownership was proven at first creation.
                return new DatasetCreationResult($dataset, false, null);
            }

            return new DatasetCreationResult($dataset, true, $this->grantTokens->issue($dataset));
        });
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

        $result = $this->database->transaction(fn (): Dataset => $this->datasets->firstOrCreate($datasetId, [
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
        ]));
        if ($result->status !== Dataset::STATUS_ACTIVE) {
            throw DatasetInactiveException::forId($datasetId);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function delete(string $datasetId): ?array
    {
        $dataset = $this->database->transaction(fn () => $this->datasets->archiveForDeletion($datasetId));

        return $dataset ? ['dataset_id' => $datasetId, 'status' => $dataset->status,
            'cleanup_pending' => $dataset->status !== Dataset::STATUS_DELETED] : null;
    }

    /** The archived row is the durable work item, including after a process restart. */
    public function resumeArchivedDeletions(): void
    {
        foreach ($this->datasets->pendingDeletions() as $dataset) {
            try {
                $result = $this->cleanup($dataset->dataset_id);
                if ($result && ! ($result['dataset_deleted'] ?? false)) {
                    $blocking = $result['blocking_sources'] ?? [];
                    $this->logger->warning('Dataset cleanup remains pending.', ['dataset_id' => $dataset->dataset_id,
                        'message' => $result['message'] ?? 'Scoped sink cleanup is incomplete.',
                        'blocking_sources' => array_slice($blocking, 0, 20),
                        'blocking_sources_total' => count($blocking)]);
                }
            } catch (\Throwable $error) {
                $this->logger->error('Dataset cleanup will retry on the next scan.', ['dataset_id' => $dataset->dataset_id, 'exception' => $error]);
            }
        }
    }

    /** Resume existing document operations; never reactivate a dataset or start replacement ingestion. */
    public function cleanup(string $datasetId): ?array
    {
        $dataset = $this->datasets->findByDatasetId($datasetId);
        if (! $dataset) {
            return null;
        }
        if ($dataset->status !== Dataset::STATUS_ARCHIVED) {
            return ['dataset_deleted' => $dataset->status === Dataset::STATUS_DELETED];
        }

        $operations = [];
        foreach ($this->managedDocuments->undeletedForDataset($datasetId) as $document) {
            try {
                $replacement = $this->deletionOperations->blocking($document->document_id);
                if ($replacement?->purpose === 'update') {
                    if ($replacement->status !== 'preparing' || ! empty($replacement->attempts['dispatch_sequence'])) {
                        $replacement = $this->managedDeletion->dispatch($replacement);
                        if ($replacement->status !== 'completed') {
                            $operations[] = $replacement;

                            continue;
                        }
                    }
                    // Reuse verified cleanup without starting a replacement in an archived dataset.
                    $this->managedDeletion->abandonReplacement($replacement);
                }
                $operation = $this->managedDeletion->dispatch($this->managedDeletion->prepare($document, 'delete', null));
                $operations[] = $operation;
            } catch (ManagedDeletionException $error) {
                return ['dataset_deleted' => false, 'status' => 409, 'message' => $error->getMessage()];
            }
        }

        $pending = array_filter($operations, fn ($op): bool => $op->status !== 'completed');
        if ($pending) {
            return [
                'dataset_deleted' => false,
                'status' => 202,
                'message' => 'Dataset document cleanup is pending verification; background cleanup will retry.',
                'operations' => array_values(array_map(fn ($op): array => [
                    'operation_id' => $op->operation_id,
                    'status' => $op->status,
                    'document_id' => $op->document_id,
                ], $pending)),
            ];
        }
        $blocking = $this->datasets->unverifiedSources($dataset);
        if ($blocking->isNotEmpty()) {
            return ['dataset_deleted' => false, 'status' => 409,
                'message' => 'Dataset has unverified or untracked writers; existing cleanup and reconciliation must finish first.',
                'blocking_sources' => $blocking->map(fn ($source): array => [
                    'source_id' => (string) $source->source_id,
                    'index_status' => (string) $source->index_status,
                    'kind' => TextIngestionMode::isDirectText(is_array($source->metadata) ? $source->metadata : []) ? 'direct_text' : 'managed',
                    'reasons' => $this->datasets->unverifiedReasons($source),
                    'remediation' => TextIngestionMode::isDirectText(is_array($source->metadata) ? $source->metadata : [])
                        ? 'Delete this direct-text source via DELETE /api/integrations/text-ingestions/{sourceId}.'
                        : 'Establish writer quiescence, then run documents:reconcile-writer for this source.',
                ])->values()->all()];
        }
        $cleanup = $this->storageCleanup->deleteStorage($dataset);
        $cleanupOk = ($cleanup['qdrant']['ok'] ?? false) && ($cleanup['neo4j']['ok'] ?? false);

        return [
            ...$cleanup,
            'dataset_deleted' => $cleanupOk ? $this->datasets->completeDeletion($dataset) : false,
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
