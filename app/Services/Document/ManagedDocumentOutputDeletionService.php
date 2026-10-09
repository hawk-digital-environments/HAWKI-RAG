<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\IngestionSource;
use App\Models\ManagedDocument;
use App\Models\ManagedDocumentDeletion;
use App\Services\Document\Clients\ManagedDocumentBridgeClient;
use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\Repositories\ManagedDocumentDeletionRepository;
use App\Services\Document\Repositories\ManagedDocumentOutputRepository;
use App\Services\Document\Repositories\ManagedDocumentRepository;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use App\Services\Pipeline\Repositories\PipelineTransactionRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class ManagedDocumentOutputDeletionService
{
    public function __construct(
        private ManagedDocumentBridgeClient $bridge,
        private ManagedDocumentDeletionRepository $operations,
        private ManagedDocumentRepository $documents,
        private ManagedDocumentSyncService $sync,
        private ManagedDocumentOutputRepository $outputs,
        private IngestionSourceRepository $sources,
        private PipelineTransactionRepository $transactions,
        private ConfigRepository $config,
        private ClockInterface $clock = new Clock,
    ) {}

    public function pending(string $documentId): bool
    {
        return $this->operations->blocksSync($documentId);
    }

    public function hasRequest(string $documentId, ?string $key): bool
    {
        return $this->operations->forRequest($documentId, 'update', $key === null ? null : hash('sha256', $key)) !== null;
    }

    /** Persist the immutable scope before any network call. */
    public function prepare(ManagedDocument $document, string $purpose, ?string $key, ?string $requestHash = null): ManagedDocumentDeletion
    {
        return $this->transactions->run(function () use ($document, $purpose, $key, $requestHash): ManagedDocumentDeletion {
            $document = $this->operations->lockDocument($document->document_id);
            $keyHash = $key === null ? null : hash('sha256', $key);
            $existing = $this->operations->forRequest($document->document_id, $purpose, $keyHash);
            $latest = $this->operations->latest($document->document_id);
            $existing ??= $latest && in_array($latest->status, ['pending', 'failed', 'completed'], true) ? $latest : null;
            if ($existing) {
                if ($existing->purpose !== $purpose || $existing->request_hash !== $requestHash) {
                    throw ManagedDeletionException::conflict('A different managed-document operation must be recovered first.');
                }
                return $existing;
            }
            if ($document->deleted_at !== null) {
                throw ManagedDeletionException::conflict('A deleted document cannot be replaced.');
            }
            $document = $this->sync->sync($document);
            $outputs = $this->outputs->backfillScopes($document, $this->outputs->activeForDocument($document->document_id));
            $targets = [];
            $writers = [];
            $sourceIds = $outputs->pluck('source_id')->push($document->latest_source_id)->filter()->unique()->sort()->values();
            foreach ($sourceIds as $id) {
                $source = $this->sources->lockBySourceId($id);
                if (! $source || $source->dataset_id !== $document->dataset_id
                    || ($source->metadata['request']['managed_document_id'] ?? null) !== $document->document_id) {
                    throw ManagedDeletionException::conflict('Indexed source ownership cannot be established.');
                }
                if (in_array($source->index_status, [IngestionSource::STATUS_RUNNING, IngestionSource::STATUS_PENDING], true)
                    || $source->temporal_schedule_id) {
                    throw ManagedDeletionException::conflict('An active or scheduled ingestion must finish before managed deletion.');
                }
                $workflowId = $source->temporal_workflow_id;
                $runId = $source->metadata['temporal']['run_id'] ?? $source->metadata['worker_event']['run_id'] ?? null;
                if ($workflowId && ! $runId) {
                    throw ManagedDeletionException::conflict('The ingestion workflow run must be reconciled before deletion.');
                }
                if ($workflowId) {
                    $writers[] = ['workflow_id' => $workflowId, 'run_id' => $runId];
                }
            }
            foreach ($outputs as $output) {
                if (! $output->source_id || ! $output->bridge_document_id || ! trim((string) $output->qdrant_collection)) {
                    throw ManagedDeletionException::conflict('Indexed output has no trusted document, source or collection scope.');
                }
                if ($document->graph_enabled && ! $output->neo4j_namespace) {
                    throw ManagedDeletionException::conflict('Graph-enabled output has no trusted graph namespace.');
                }
                $targets[] = [
                    'output_id' => $output->id, 'doc_id' => $output->bridge_document_id,
                    'source_id' => $output->source_id, 'collection' => $output->qdrant_collection,
                    'neo4j_namespace' => $output->neo4j_namespace,
                ];
            }
            $operationId = 'delete_'.hash('sha256', json_encode([
                $document->document_id, $document->dataset_id, $purpose, $keyHash, $requestHash, $targets,
                $latest?->operation_id,
            ], JSON_THROW_ON_ERROR));
            $operation = $this->operations->create([
                'operation_id' => $operationId, 'document_id' => $document->document_id,
                'dataset_id' => $document->dataset_id, 'purpose' => $purpose,
                'request_key' => $keyHash, 'request_hash' => $requestHash, 'status' => 'pending',
                'workflow_id' => 'managed-'.$operationId,
                'workflow_input' => [
                    'operation_id' => $operationId, 'managed_document_id' => $document->document_id,
                    'dataset_id' => $document->dataset_id, 'targets' => $targets, 'writers' => $writers,
                    'task_queues' => $this->config->get('temporal.task_queues'),
                ],
            ]);
            foreach ($sourceIds as $id) {
                $this->sources->markManagedDeletion($this->sources->findBySourceId($id), $operationId, IngestionSource::STATUS_DELETING);
            }
            $this->documents->save($document, ['status' => ManagedDocument::STATUS_DELETING, 'last_error' => null]);
            return $operation;
        });
    }

    /** Unknown startup leaves the same operation available for redispatch. */
    public function dispatch(ManagedDocumentDeletion $operation): ManagedDocumentDeletion
    {
        if (in_array($operation->status, ['completed', 'continued'], true)) {
            return $operation;
        }
        try {
            $receipt = $this->bridge->deleteOutputs($operation->workflow_id, $operation->workflow_input);
            if (($receipt['status'] ?? null) === 'completed') {
                $result = $receipt['result'] ?? [];
                if ($this->canonicalJson($result['input'] ?? null) !== $this->canonicalJson($operation->workflow_input)) {
                    throw new \RuntimeException('Deletion result does not match the recorded scope.');
                }
                return $this->applyReceipt([
                    'operation_id' => $operation->operation_id, 'workflow_id' => $operation->workflow_id,
                    'run_id' => $receipt['run_id'], 'status' => 'completed', 'results' => $result['results'] ?? [],
                ]);
            }
            return $this->transactions->run(function () use ($operation, $receipt): ManagedDocumentDeletion {
                $this->operations->lockDocument($operation->document_id);
                $current = $this->operations->lock($operation->operation_id);
                if (in_array($current->status, ['completed', 'continued'], true)) {
                    return $current;
                }
                return $this->operations->save($current, ['run_id' => $receipt['run_id'] ?? $current->run_id, 'last_error' => null]);
            });
        } catch (\Throwable $error) {
            return $this->transactions->run(function () use ($operation, $error): ManagedDocumentDeletion {
                $this->operations->lockDocument($operation->document_id);
                $current = $this->operations->lock($operation->operation_id);
                return in_array($current->status, ['completed', 'continued'], true) ? $current
                    : $this->operations->save($current, ['last_error' => 'Startup or completion is unconfirmed: '.$error->getMessage()]);
            });
        }
    }

    /** Only the signed worker boundary or a trusted orchestration response calls this. */
    public function applyReceipt(array $receipt): ManagedDocumentDeletion
    {
        $operation = $this->operations->find($receipt['operation_id']);
        if (! $operation) {
            throw ManagedDeletionException::conflict('Unknown deletion operation.');
        }
        return $this->transactions->run(function () use ($operation, $receipt): ManagedDocumentDeletion {
            $document = $this->operations->lockDocument($operation->document_id);
            $operation = $this->operations->lock($operation->operation_id);
            if ($receipt['workflow_id'] !== $operation->workflow_id || empty($receipt['run_id'])) {
                throw ManagedDeletionException::conflict('Deletion callback workflow identity is invalid.');
            }
            if (in_array($operation->status, ['completed', 'continued'], true)) {
                return $operation;
            }
            if ($receipt['status'] === 'failed') {
                if ($operation->run_id && $operation->run_id !== $receipt['run_id']) {
                    return $operation;
                }
                return $this->operations->save($operation, [
                    'status' => 'failed', 'run_id' => $receipt['run_id'], 'results' => $receipt['results'],
                    'last_error' => 'Sink cleanup failed; retry the original request.',
                ]);
            }
            $targets = $operation->workflow_input['targets'];
            $results = $receipt['results'];
            if (count($results) !== count($targets)) {
                throw ManagedDeletionException::conflict('Deletion callback has incomplete sink evidence.');
            }
            $byId = [];
            foreach ($results as $result) {
                $id = $result['output_id'] ?? null;
                if (isset($byId[$id])) {
                    throw ManagedDeletionException::conflict('Deletion callback has duplicate output evidence.');
                }
                $byId[$id] = $result;
            }
            foreach ($targets as $target) {
                $proof = $byId[$target['output_id']] ?? [];
                $vector = $proof['qdrant'] ?? [];
                $graph = $proof['neo4j'] ?? [];
                if (($vector['verified'] ?? null) !== true || ($vector['remaining_points'] ?? null) !== 0
                    || ($vector['doc_id'] ?? null) !== $target['doc_id'] || ($vector['collection'] ?? null) !== $target['collection']
                    || ($graph['verified'] ?? null) !== true || ($graph['remaining_contributions'] ?? null) !== 0
                    || ($graph['required'] ?? null) !== ($target['neo4j_namespace'] !== null)
                    || ($target['neo4j_namespace'] !== null && (($graph['namespace'] ?? null) !== $target['neo4j_namespace']
                        || ($graph['doc_id'] ?? null) !== $target['doc_id']))) {
                    throw ManagedDeletionException::conflict('Deletion callback has no verified scoped sink evidence.');
                }
            }
            $now = Carbon::instance($this->clock->now());
            $this->outputs->deactivateSelected($document, $targets, $now);
            foreach (array_unique(array_filter([...array_column($targets, 'source_id'), $document->latest_source_id])) as $id) {
                $source = $this->sources->findBySourceId($id);
                if ($source) {
                    $this->sources->markManagedDeletion($source, $operation->operation_id, IngestionSource::STATUS_DELETED);
                }
            }
            $this->documents->save($document, [
                'status' => $operation->purpose === 'delete' ? ManagedDocument::STATUS_DELETED : ManagedDocument::STATUS_DELETING,
                'deleted_at' => $operation->purpose === 'delete' ? $now : null, 'last_error' => null,
            ]);
            return $this->operations->save($operation, [
                'status' => 'completed', 'run_id' => $receipt['run_id'], 'results' => $results, 'last_error' => null,
            ]);
        });
    }

    public function continueUpdate(ManagedDocumentDeletion $operation, callable $replace): array
    {
        // Serializes concurrent PUT continuations. The upload task has a durable deterministic identity.
        return $this->transactions->run(function () use ($operation, $replace): array {
            $this->operations->lockDocument($operation->document_id);
            $operation = $this->operations->lock($operation->operation_id);
            if ($operation->status === 'continued') {
                return $operation->replacement_result;
            }
            if ($operation->status !== 'completed') {
                throw ManagedDeletionException::conflict('Replacement must wait for verified cleanup.');
            }
            $result = $replace('task_managed_replace_'.substr($operation->operation_id, 7));
            if (($result['payload']['success'] ?? false) === true) {
                $this->operations->save($operation, ['status' => 'continued', 'replacement_result' => $result]);
            }
            return $result;
        });
    }
    private function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }
            $value = array_map(fn ($item) => json_decode($this->canonicalJson($item), true, flags: JSON_THROW_ON_ERROR), $value);
        }
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
