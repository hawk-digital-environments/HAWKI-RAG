<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\IngestionSource;
use App\Models\ManagedDocument;
use App\Models\ManagedDocumentDeletion;
use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\Repositories\ManagedDocumentDeletionRepository;
use App\Services\Document\Repositories\ManagedDocumentOutputRepository;
use App\Services\Document\Repositories\ManagedDocumentRepository;
use App\Services\Document\Repositories\ManagedDocumentWriterRepository;
use App\Services\Document\Values\ManagedDocumentId;
use App\Services\Pipeline\Clients\PythonTemporalBridgeClient;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use App\Services\Pipeline\Repositories\PipelineTransactionRepository;
use App\Services\Pipeline\Tasks\PipelineTaskCancellationService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class ManagedDocumentOutputDeletionService
{
    public function __construct(
        private PythonTemporalBridgeClient $bridge,
        private ManagedDocumentDeletionRepository $operations,
        private ManagedDocumentRepository $documents,
        private ManagedDocumentSyncService $sync,
        private ManagedDocumentOutputRepository $outputs,
        private IngestionSourceRepository $sources,
        private PipelineTransactionRepository $transactions,
        private ConfigRepository $config,
        private ManagedDocumentWriterRepository $writerJobs,
        private PipelineTaskCancellationService $cancellations,
        private ClockInterface $clock = new Clock,
    ) {}

    public function pending(string $documentId): bool
    {
        return $this->operations->blocking($documentId) !== null;
    }

    public function hasRequest(string $documentId, ?string $key): bool
    {
        return $this->operations->forRequest($documentId, 'update', $key === null ? null : hash('sha256', $key)) !== null;
    }

    /** Persist the immutable scope before any network call. */
    public function prepare(ManagedDocument $document, string $purpose, ?string $key, ?string $requestHash = null): ManagedDocumentDeletion
    {
        $blocking = $this->operations->blocking($document->document_id);
        $cancel = [];
        if ($purpose === 'delete' && (! $blocking || $blocking->purpose === 'delete')) {
            foreach ($this->sources->ownedManagedSources($document->document_id, $document->dataset_id) as $source) {
                if ($source->index_status === IngestionSource::STATUS_DELETED) {
                    continue;
                }
                // Closing a gate is durable even when writer quiescence is still uncertain.
                $active = in_array($source->index_status, [IngestionSource::STATUS_RUNNING, IngestionSource::STATUS_PENDING], true);
                $this->sources->markCancelled($source, $active);
                if ($source->task_id && $active && ($source->metadata['managed_dispatch']['attempted'] ?? null) !== false) {
                    $cancel[] = [$source->task_id, $source->source_id];
                }
            }
        }

        $operation = $this->transactions->run(function () use ($document, $purpose, $key, $requestHash): ManagedDocumentDeletion {
            $document = $this->operations->lockDocument($document->document_id);
            $keyHash = $key === null ? null : hash('sha256', $key);
            $existing = $this->operations->forRequest($document->document_id, $purpose, $keyHash);
            $latest = $this->operations->latest($document->document_id);
            $existing ??= $this->operations->blocking($document->document_id);
            if ($existing) {
                $this->assertEffectiveGraphScope($existing);
                if (($existing->attempts['writer_protocol'] ?? null) !== 1) {
                    throw ManagedDeletionException::conflict('This operation predates registered writer evidence. Stop its workflow and reconcile its full historical scope before retiring the old operation and retrying with a new key.');
                }
                if ($existing->status === 'abandoned') {
                    throw ManagedDeletionException::conflict('The replacement was abandoned before startup; use a new Idempotency-Key for corrected input.');
                }
                if ($existing->purpose !== $purpose || $existing->request_hash !== $requestHash) {
                    throw ManagedDeletionException::conflict('A different managed-document operation must be recovered first.');
                }

                return $existing;
            }
            if ($document->deleted_at !== null) {
                throw ManagedDeletionException::conflict('A deleted document cannot be replaced.');
            }
            $document = $this->sync->sync($document);
            $owned = $this->sources->ownedManagedSources($document->document_id, $document->dataset_id);
            foreach ($owned as $source) {
                $source = $this->sources->lockBySourceId($source->source_id);
                if (isset($source->metadata['managed_deletion']) && $source->index_status === IngestionSource::STATUS_DELETED) {
                    continue;
                }
                foreach ($source->metadata['managed_targets'] ?? [] as $docId => $target) {
                    $job = $this->writerJobs->job($target['job_id']);
                    if (! $job) {
                        throw ManagedDeletionException::conflict('Registered partial output job is missing; restore ingestion metadata before deletion.');
                    }
                    $this->outputs->registerWriterTarget($document, $source, $job, ['doc_id' => $docId, 'relative_path' => $target['relative_path']],
                        ['collection' => $target['collection'], 'graph' => $target['neo4j_namespace'] !== null, 'neo4j_namespace' => $target['neo4j_namespace']]);
                }
            }
            $outputs = $this->outputs->backfillScopes($this->outputs->unverifiedForDocument($document->document_id));
            if ($outputs->count() > 2000) {
                throw ManagedDeletionException::conflict('Managed deletion exceeds the supported output limit of 2000.');
            }
            $targets = [];
            $writers = [];
            $sourceStates = [];
            $sourceIds = $outputs->pluck('source_id')->merge($owned->pluck('source_id'))->push($document->latest_source_id)->filter()->unique()->sort()->values();
            foreach ($sourceIds as $id) {
                $source = $this->sources->lockBySourceId($id);
                $sourceStates[$id] = $source?->index_status;
                $request = $source?->metadata['request'] ?? [];
                $request = is_array($request) ? $request : [];
                $owner = ManagedDocumentId::fromRequestMetadata(is_array($request['metadata'] ?? null) ? $request['metadata'] : $request);
                if (! $source || $source->dataset_id !== $document->dataset_id
                    || $owner?->value !== $document->document_id) {
                    throw ManagedDeletionException::conflict('Indexed source ownership cannot be established.');
                }
                if (isset($source->metadata['managed_deletion']) && $source->index_status === IngestionSource::STATUS_DELETED) {
                    continue; // Prior verified cleanup or never-dispatched abandonment fenced this source permanently.
                }
                if (isset($source->metadata['managed_dispatch']) && ($source->metadata['managed_dispatch']['attempted'] ?? null) === false) {
                    continue; // SQL preparation exists, but no external dispatch has ever been attempted.
                }
                if (($source->metadata['managed_writer_protocol'] ?? null) !== 1) {
                    throw ManagedDeletionException::conflict('Managed writer evidence is missing. Reconcile historical manifests and stop untracked writers before deletion.');
                }
                $awaitingWriter = $purpose === 'delete' && ! empty($source->metadata['managed_writers'])
                    && collect($source->metadata['managed_writers'])->contains(fn ($writer): bool => $writer['status'] !== 'released');
                if (($source->metadata['managed_target_manifest_complete'] ?? null) !== true && ! $awaitingWriter) {
                    throw ManagedDeletionException::conflict('The owned target manifest is missing or incomplete; reconcile converter artifacts before cleanup.');
                }
                foreach ($source->metadata['managed_writers'] ?? [] as $writer) {
                    if ($writer['status'] !== 'released' && $purpose !== 'delete') {
                        throw ManagedDeletionException::conflict('Managed writer is active or uncertain. Stop and reconcile that execution before deletion; workflow cancellation alone is insufficient.');
                    }
                }
                if (($purpose !== 'delete' && in_array($source->index_status, [IngestionSource::STATUS_RUNNING, IngestionSource::STATUS_PENDING], true))
                    || $source->temporal_schedule_id) {
                    throw ManagedDeletionException::conflict('An active or scheduled ingestion must finish before managed deletion.');
                }
                $workflowId = $source->temporal_workflow_id;
                $runId = $source->metadata['temporal']['run_id'] ?? $source->metadata['worker_event']['run_id'] ?? null;
                if (($workflowId && ! $runId) || (! $workflowId && in_array($source->index_status, [IngestionSource::STATUS_FAILED, IngestionSource::STATUS_CANCELLED], true))) {
                    throw ManagedDeletionException::conflict('The ingestion workflow run must be reconciled before deletion.');
                }
                if ($workflowId) {
                    $writers[] = ['workflow_id' => $workflowId, 'run_id' => $runId];
                }
                foreach ($source->metadata['managed_writer_history'] ?? [] as $writer) {
                    if (empty($writer['run_id'])) {
                        throw ManagedDeletionException::conflict('A historical writer start is unconfirmed; reconcile its recorded workflow identity.');
                    }
                    $writers[] = $writer;
                }
            }
            foreach ($outputs as $output) {
                if (! $output->source_id || ! $output->bridge_document_id || ! trim((string) $output->qdrant_collection)) {
                    throw ManagedDeletionException::conflict('Indexed output has no trusted document, source or collection scope.');
                }
                $source = $this->sources->findBySourceId($output->source_id);
                $historical = $source->metadata['managed_dispatch']['input']['ingestion'] ?? $source->metadata['dataset'] ?? [];
                if ($output->neo4j_namespace === null
                    && ($source->metadata['managed_targets'][$output->bridge_document_id]['graph_scope_resolved'] ?? null) !== true) {
                    throw ManagedDeletionException::conflict('Historical output has unresolved effective graph scope; reconcile converter evidence before cleanup.');
                }
                if (($historical['graph'] ?? false) && ! $output->neo4j_namespace) {
                    throw ManagedDeletionException::conflict('Graph-enabled output has no trusted graph namespace.');
                }
                $targets[] = [
                    'output_id' => $output->id, 'doc_id' => $output->bridge_document_id,
                    'source_id' => $output->source_id, 'collection' => $output->qdrant_collection,
                    'neo4j_namespace' => $output->neo4j_namespace,
                ];
            }
            $writers = array_values(array_unique($writers, SORT_REGULAR));
            $operationId = 'delete_'.hash('sha256', json_encode([
                $document->document_id, $document->dataset_id, $purpose, $keyHash, $requestHash, $targets,
                $latest?->operation_id,
            ], JSON_THROW_ON_ERROR));
            $operation = $this->operations->create([
                'operation_id' => $operationId, 'document_id' => $document->document_id,
                'dataset_id' => $document->dataset_id, 'purpose' => $purpose,
                'request_key' => $keyHash, 'request_hash' => $requestHash, 'status' => $purpose === 'update' ? 'preparing' : 'pending',
                'workflow_id' => 'managed-'.$operationId,
                'attempts' => ['writer_protocol' => 1, 'effective_graph_scope' => true, 'source_states' => $sourceStates, 'document_status' => $document->status],
                'workflow_input' => [
                    'operation_id' => $operationId, 'managed_document_id' => $document->document_id,
                    'dataset_id' => $document->dataset_id, 'targets' => $targets, 'writers' => $writers,
                    'task_queues' => $this->config->get('temporal.task_queues'),
                ],
            ]);
            foreach ($sourceIds as $id) {
                $source = $this->sources->findBySourceId($id);
                if ($source->index_status !== IngestionSource::STATUS_DELETED) {
                    $this->sources->markManagedDeletion($source, $operationId, IngestionSource::STATUS_DELETING);
                }
            }
            $this->documents->save($document, ['status' => ManagedDocument::STATUS_DELETING, 'last_error' => null]);

            return $operation;
        });
        foreach ($cancel as [$taskId, $sourceId]) {
            try {
                $this->cancellations->cancel($taskId, $sourceId);
            } catch (\Throwable $error) {
                $operation = $this->transactions->run(function () use ($operation, $error): ManagedDocumentDeletion {
                    $this->operations->lockDocument($operation->document_id);
                    $current = $this->operations->lock($operation->operation_id);

                    return $current->status === 'pending'
                        ? $this->operations->save($current, ['attempts' => [...$current->attempts, 'cancellation_error' => $error->getMessage()]]) : $current;
                });
            }
        }

        return $operation;
    }

    /** Unknown startup leaves the same operation available for redispatch. */
    public function dispatch(ManagedDocumentDeletion $operation): ManagedDocumentDeletion
    {
        $this->assertEffectiveGraphScope($operation);
        if (($operation->attempts['writer_protocol'] ?? null) !== 1) {
            throw ManagedDeletionException::conflict('Legacy deletion scope requires reconciliation before dispatch.');
        }
        if (in_array($operation->status, ['preparing', 'completed', 'continued', 'abandoned'], true)) {
            return $operation;
        }
        $ready = false;
        $operation = $this->transactions->run(function () use ($operation, &$ready): ManagedDocumentDeletion {
            $this->operations->lockDocument($operation->document_id);
            $current = $this->operations->lock($operation->operation_id);
            if (in_array($current->status, ['preparing', 'completed', 'continued', 'abandoned'], true)) {
                return $current;
            }
            if (! $this->writersReleased($current)) {
                return $current; // Durable pending operation; never infer drain from workflow closure.
            }
            $ready = true;
            $attempts = $current->attempts ?? [];
            $attempts['dispatch_sequence'] = ($attempts['dispatch_sequence'] ?? 0) + 1;

            return $this->operations->save($current, ['attempts' => $attempts]);
        });
        if (! $ready || in_array($operation->status, ['preparing', 'completed', 'continued', 'abandoned'], true)) {
            return $operation;
        }
        $sequence = $operation->attempts['dispatch_sequence'];
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

            return $this->transactions->run(function () use ($operation, $receipt, $sequence): ManagedDocumentDeletion {
                $this->operations->lockDocument($operation->document_id);
                $current = $this->operations->lock($operation->operation_id);
                if (in_array($current->status, ['completed', 'continued', 'abandoned'], true)
                    || ($current->attempts['dispatch_sequence'] ?? null) !== $sequence) {
                    return $current;
                }
                $runId = $receipt['run_id'] ?? null;
                if (! is_string($runId) || $runId === '' || ! in_array($receipt['status'] ?? null, ['pending', 'failed'], true)) {
                    throw ManagedDeletionException::conflict('Deletion acknowledgement has no confirmed run identity.');
                }
                $attempts = $current->attempts ?? [];
                if (($attempts['runs'][$runId]['superseded'] ?? false) && $current->run_id !== $runId) {
                    return $current; // A delayed receipt cannot adopt an already superseded run.
                }
                $failed = ($receipt['status'] === 'failed') || (($attempts['runs'][$runId]['status'] ?? null) === 'failed');
                $attempts['runs'][$runId] = ['status' => $failed ? 'failed' : 'pending'];
                if ($current->run_id && $current->run_id !== $runId) {
                    $attempts['runs'][$current->run_id]['superseded'] = true;
                }

                return $this->operations->save($current, [
                    'run_id' => $runId, 'attempts' => $attempts,
                    'status' => $failed ? 'failed' : 'pending',
                    'last_error' => $failed ? ($current->last_error ?? 'Deletion workflow failed; retry the original request.') : null,
                ]);
            });
        } catch (\Throwable $error) {
            return $this->transactions->run(function () use ($operation, $error, $sequence): ManagedDocumentDeletion {
                $this->operations->lockDocument($operation->document_id);
                $current = $this->operations->lock($operation->operation_id);

                return in_array($current->status, ['completed', 'continued', 'failed', 'abandoned'], true)
                    || ($current->attempts['dispatch_sequence'] ?? null) !== $sequence ? $current
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
            $this->assertEffectiveGraphScope($operation);
            if (($operation->attempts['writer_protocol'] ?? null) !== 1) {
                throw ManagedDeletionException::conflict('Legacy deletion completion has no registered writer proof; reconcile it before accepting completion.');
            }
            if ($receipt['workflow_id'] !== $operation->workflow_id || empty($receipt['run_id'])) {
                throw ManagedDeletionException::conflict('Deletion callback workflow identity is invalid.');
            }
            if (in_array($operation->status, ['completed', 'continued', 'abandoned'], true)) {
                return $operation;
            }
            if (! in_array($receipt['status'] ?? null, ['completed', 'failed'], true)) {
                throw ManagedDeletionException::conflict('Deletion callback status is invalid.');
            }
            if ($receipt['status'] === 'failed') {
                $attempts = $operation->attempts ?? [];
                $attempts['runs'][$receipt['run_id']]['status'] = 'failed';
                if ($operation->run_id && $operation->run_id !== $receipt['run_id']) {
                    return $this->operations->save($operation, ['attempts' => $attempts]);
                }

                return $this->operations->save($operation, [
                    'status' => 'failed', 'run_id' => $receipt['run_id'], 'results' => $receipt['results'], 'attempts' => $attempts,
                    'last_error' => 'Sink cleanup failed; retry the original request.',
                ]);
            }
            if (! $this->writersReleased($operation)) {
                throw ManagedDeletionException::conflict('Writer completion is not acknowledged; cleanup must remain pending.');
            }
            $targets = $operation->workflow_input['targets'];
            $results = $receipt['results'];
            if (count($results) !== count($targets)) {
                throw ManagedDeletionException::conflict('Deletion callback has incomplete sink evidence.');
            }
            $byId = [];
            foreach ($results as $result) {
                $id = is_array($result) ? ($result['output_id'] ?? null) : null;
                if (! is_int($id) || $id < 1 || isset($byId[$id])) {
                    throw ManagedDeletionException::conflict('Deletion callback has duplicate output evidence.');
                }
                $byId[$id] = $result;
            }
            foreach ($targets as $target) {
                $proof = $byId[$target['output_id']] ?? [];
                $vector = $proof['qdrant'] ?? [];
                $graph = $proof['neo4j'] ?? [];
                if (($vector['verified'] ?? null) !== true || ($vector['remaining_points'] ?? null) !== 0
                    || ($vector['dataset_id'] ?? null) !== $operation->dataset_id || ($vector['source_id'] ?? null) !== $target['source_id']
                    || ($vector['doc_id'] ?? null) !== $target['doc_id'] || ($vector['collection'] ?? null) !== $target['collection']
                    || ($graph['verified'] ?? null) !== true || ($graph['remaining_contributions'] ?? null) !== 0
                    || ($graph['required'] ?? null) !== ($target['neo4j_namespace'] !== null)
                    || ($target['neo4j_namespace'] !== null && (($graph['dataset_id'] ?? null) !== $operation->dataset_id || ($graph['namespace'] ?? null) !== $target['neo4j_namespace']
                        || ($graph['doc_id'] ?? null) !== $target['doc_id']))) {
                    throw ManagedDeletionException::conflict('Deletion callback has no verified scoped sink evidence.');
                }
            }
            $now = Carbon::instance($this->clock->now());
            $this->outputs->deactivateSelected($document, $targets, $now);
            foreach (array_unique(array_filter([...array_keys($operation->attempts['source_states'] ?? []), ...array_column($targets, 'source_id'), $document->latest_source_id])) as $id) {
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

    private function writersReleased(ManagedDocumentDeletion $operation): bool
    {
        if (! is_array($operation->attempts['source_states'] ?? null)) {
            return false;
        }
        $targets = [];
        foreach ($operation->workflow_input['targets'] as $target) {
            $targets[$target['source_id'].'|'.$target['doc_id']] = $target;
        }
        foreach (array_keys($operation->attempts['source_states'] ?? []) as $id) {
            $source = $this->sources->lockBySourceId($id);
            if (! $source || $source->dataset_id !== $operation->dataset_id) {
                return false;
            }
            if (($source->metadata['managed_dispatch']['attempted'] ?? null) === false) {
                continue;
            }
            if ($source->index_status === IngestionSource::STATUS_DELETED
                && ($source->metadata['managed_deletion']['status'] ?? null) === IngestionSource::STATUS_DELETED) {
                continue;
            }
            if (($source->metadata['managed_writer_protocol'] ?? null) !== 1
                || ($source->metadata['managed_target_manifest_complete'] ?? null) !== true
                || collect($source->metadata['managed_writers'] ?? [])->contains(fn ($writer): bool => $writer['status'] !== 'released')) {
                return false;
            }
            foreach ($source->metadata['managed_targets'] ?? [] as $docId => $scope) {
                $target = $targets[$id.'|'.$docId] ?? null;
                if (! $target || $target['collection'] !== $scope['collection'] || $target['neo4j_namespace'] !== $scope['neo4j_namespace']) {
                    return false; // A changed historical manifest cannot expand an immutable cleanup snapshot.
                }
            }
        }

        return true;
    }

    public function continueUpdate(ManagedDocumentDeletion $operation, callable $dispatch, callable $publish): array
    {
        $generation = null;
        $recorded = $this->transactions->run(function () use ($operation, &$generation): ?array {
            $document = $this->operations->lockDocument($operation->document_id);
            $operation = $this->operations->lock($operation->operation_id);
            if ($operation->status === 'continued') {
                return $operation->replacement_result;
            }
            if ($operation->status !== 'completed') {
                throw ManagedDeletionException::conflict('Replacement must wait for verified cleanup.');
            }
            if ($document->deleted_at !== null || $this->operations->blocking($document->document_id)?->operation_id !== $operation->operation_id) {
                throw ManagedDeletionException::conflict('Replacement operation is no longer current.');
            }
            $generation = [$document->latest_source_id, $document->latest_task_id, $document->latest_job_id];

            return null;
        });
        if ($recorded !== null) {
            return $recorded;
        }
        // Preparation is committed. Temporal dispatch and status reads must occur outside SQL.
        $dispatched = $dispatch('task_managed_replace_'.substr($operation->operation_id, 7));

        return $this->transactions->run(function () use ($operation, $dispatched, $publish, $generation): array {
            $document = $this->operations->lockDocument($operation->document_id);
            $operation = $this->operations->lock($operation->operation_id);
            if ($operation->status === 'continued') {
                return $operation->replacement_result;
            }
            if ($operation->status !== 'completed' || $document->deleted_at !== null
                || $this->operations->blocking($document->document_id)?->operation_id !== $operation->operation_id
                || [$document->latest_source_id, $document->latest_task_id, $document->latest_job_id] !== $generation) {
                throw ManagedDeletionException::conflict('Replacement publication was superseded; document state was preserved.');
            }
            // Publication and its operation transition commit together. No network I/O here.
            $result = $publish($document, $dispatched);
            if (($result['payload']['success'] ?? false) === true) {
                $this->operations->save($operation, ['status' => 'continued', 'replacement_result' => $result]);
            }

            return $result;
        });
    }

    public function prepareReplacement(ManagedDocumentDeletion $operation, callable $prepare): array
    {
        return $this->transactions->run(function () use ($operation, $prepare): array {
            $this->operations->lockDocument($operation->document_id);
            $operation = $this->operations->lock($operation->operation_id);
            if ($operation->replacement !== null) {
                return $operation->replacement;
            }
            if ($operation->status !== 'preparing') {
                throw ManagedDeletionException::conflict('Replacement preparation must precede cleanup dispatch.');
            }
            $result = $prepare('task_managed_replace_'.substr($operation->operation_id, 7));
            if (($result['payload']['success'] ?? false) !== true) {
                throw ManagedDeletionException::conflict('Replacement could not be durably prepared: '.($result['payload']['message'] ?? 'restore the original file and retry'));
            }
            $this->operations->save($operation, ['replacement' => $result, 'status' => 'pending']);

            return $result;
        });
    }

    public function abandonReplacement(ManagedDocumentDeletion $operation): void
    {
        $this->transactions->run(function () use ($operation): void {
            $document = $this->operations->lockDocument($operation->document_id);
            $operation = $this->operations->lock($operation->operation_id);
            $sourceId = $operation->replacement['payload']['source_id'] ?? null;
            if ($sourceId) {
                $source = $this->sources->lockBySourceId($sourceId);
                if ($source->metadata['managed_dispatch']['attempted'] ?? false) {
                    throw ManagedDeletionException::conflict('Replacement startup is uncertain; reconcile the recorded execution before changing intent.');
                }
                $this->sources->markManagedDeletion($source, $operation->operation_id, IngestionSource::STATUS_DELETED);
            }
            if ($operation->status === 'preparing' && empty($operation->attempts['dispatch_sequence'])) {
                foreach ($operation->attempts['source_states'] ?? [] as $id => $status) {
                    $source = $this->sources->lockBySourceId($id);
                    $metadata = $source->metadata ?? [];
                    unset($metadata['managed_deletion']);
                    $source->forceFill(['metadata' => $metadata, 'index_status' => $status])->save();
                }
                $this->documents->save($document, ['status' => $operation->attempts['document_status'], 'last_error' => null]);
            } elseif ($operation->status !== 'completed') {
                throw ManagedDeletionException::conflict('Only verified cleanup permits abandonment of replacement intent.');
            }
            $this->operations->save($operation, ['status' => 'abandoned', 'last_error' => 'Replacement preparation failed permanently; corrected input or DELETE is permitted.']);
        });
    }

    public function requestHash(array $input, ?string $fileName, ?string $fileHash): string
    {
        $value = json_decode(json_encode([$input, $fileName, $fileHash], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        return hash('sha256', $this->canonicalJson($value));
    }

    /** An old frozen vector-only snapshot cannot be repaired by changing its receipt. */
    private function assertEffectiveGraphScope(ManagedDocumentDeletion $operation): void
    {
        if (($operation->attempts['effective_graph_scope'] ?? null) === true || $operation->status === 'abandoned') {
            return;
        }
        throw ManagedDeletionException::conflict('This deletion predates validated effective graph-scope evidence. Reconcile and retire its frozen snapshot before retrying.');
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
