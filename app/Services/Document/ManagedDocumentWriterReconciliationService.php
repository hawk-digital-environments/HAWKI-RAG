<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\Repositories\ManagedDocumentDeletionRepository;
use App\Services\Document\Repositories\ManagedDocumentOutputRepository;
use App\Services\Document\Repositories\ManagedDocumentWriterRepository;
use App\Services\Document\Values\ManagedDocumentId;
use App\Services\Pipeline\Clients\PythonTemporalBridgeClient;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use App\Services\Pipeline\Repositories\PipelineTransactionRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class ManagedDocumentWriterReconciliationService
{
    public function __construct(private IngestionSourceRepository $sources, private ManagedDocumentWriterRepository $jobs,
        private ManagedDocumentOutputRepository $outputs, private PipelineTransactionRepository $transactions,
        private PythonTemporalBridgeClient $bridge, private Repository $config, private Filesystem $files,
        private ManagedDocumentDeletionRepository $operations,
        private ClockInterface $clock = new Clock) {}

    /** Local administrative boundary: caller must stop writers and drain sink operations first. */
    public function reconcile(string $sourceId, string $manifest, bool $confirmedQuiescence): void
    {
        if (! $confirmedQuiescence) {
            throw ManagedDeletionException::conflict('Stop all relevant workers, close ingestion executions, disable schedules, and drain in-flight sink requests before confirming quiescence.');
        }
        $source = $this->transactions->run(function () use ($sourceId) {
            $source = $this->sources->lockBySourceId($sourceId);
            if (! $source || $source->temporal_schedule_id) {
                throw ManagedDeletionException::conflict('Source must exist and have no enabled ingestion schedule.');
            }
            $request = $source->metadata['request'] ?? [];
            $owner = ManagedDocumentId::fromRequestMetadata($request['metadata'] ?? $request);
            $document = $owner ? $this->jobs->document($owner->value) : null;
            if (! $document || $document->dataset_id !== $source->dataset_id) {
                throw ManagedDeletionException::conflict('Only an owned managed source can be reconciled.');
            }
            $metadata = $source->metadata ?? [];
            $metadata['managed_writer_gate'] = 'closed';
            $source->forceFill(['metadata' => $metadata])->save();

            return $source;
        });
        // Closing the gate is committed even if later evidence validation fails.
        $history = $source->metadata['managed_writer_history'] ?? [];
        if ($source->temporal_workflow_id) {
            $history[] = ['workflow_id' => $source->temporal_workflow_id, 'run_id' => $source->metadata['temporal']['run_id'] ?? $source->metadata['worker_event']['run_id'] ?? null];
        }
        foreach ($history as $writer) {
            if (empty($writer['run_id'])) {
                throw ManagedDeletionException::conflict('A planned writer has no confirmed run; restore its actual Temporal run identity before reconciliation.');
            }
            $status = $this->bridge->workflowStatus($writer['workflow_id'], $writer['run_id']);
            if (! in_array($status['status'] ?? null, ['COMPLETED', 'FAILED', 'CANCELED', 'TERMINATED', 'TIMED_OUT'], true)) {
                throw ManagedDeletionException::conflict('Every historical workflow must close before administrative reconciliation.');
            }
        }
        $root = realpath((string) $this->config->get('temporal.storage.shared_root'));
        $path = realpath($manifest);
        if (! $root || ! $path || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            throw ManagedDeletionException::conflict('The complete owned manifest must be a readable file inside shared storage.');
        }
        try {
            $records = json_decode($this->files->get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw ManagedDeletionException::conflict('The owned manifest is corrupt: '.$error->getMessage());
        }
        if (! is_array($records) || ! array_is_list($records) || count($records) > 1000) {
            throw ManagedDeletionException::conflict('The owned manifest must list at most 1000 complete artifact identities.');
        }
        $scope = $source->metadata['managed_dispatch']['input']['ingestion'] ?? $source->metadata['dataset'] ?? [];
        $scope['collection'] ??= $scope['qdrant_collection'] ?? null;
        $scope['graph'] ??= ! empty($scope['neo4j_namespace']);
        if (empty($scope['collection']) || ($scope['graph'] && empty($scope['neo4j_namespace']))) {
            throw ManagedDeletionException::conflict('Historical storage identity is missing; current dataset configuration cannot substitute for it.');
        }
        $request = $source->metadata['request'] ?? [];
        $owner = ManagedDocumentId::fromRequestMetadata($request['metadata'] ?? $request);
        $document = $owner ? $this->jobs->document($owner->value) : null;
        if (! $document || $document->dataset_id !== $source->dataset_id) {
            throw ManagedDeletionException::conflict('Historical source ownership is ambiguous.');
        }
        $this->transactions->run(function () use ($source, $document, $records, $scope, $path): void {
            $this->operations->lockDocument($document->document_id);
            $job = $this->jobs->latestJob($source->source_id);
            $source = $this->sources->lockBySourceId($source->source_id);
            if (! $job) {
                throw ManagedDeletionException::conflict('Restore the owned source job before reconciliation.');
            }
            $metadata = $source->metadata ?? [];
            foreach ($records as $record) {
                $relative = $record['relative_path'] ?? null;
                $docId = $record['document_id'] ?? null;
                $targetScope = $scope;
                if (! $scope['graph'] && ! is_bool($record['graph'] ?? null)) {
                    throw ManagedDeletionException::conflict('Vector-only legacy artifacts require explicit effective graph evidence in the manifest.');
                }
                $targetScope['graph'] = $scope['graph'] || ($record['graph'] ?? false);
                if ($targetScope['graph'] && empty($scope['neo4j_namespace'])) {
                    throw ManagedDeletionException::conflict('Effective graph evidence has no frozen namespace.');
                }
                if (! is_string($relative) || $relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\')
                    || array_intersect(explode('/', $relative), ['', '.', '..']) !== []
                    || $docId !== 'doc_'.substr(hash('sha256', $source->source_id.'|'.$relative), 0, 40)) {
                    throw ManagedDeletionException::conflict('Manifest document identity does not match its source and relative path.');
                }
                $this->outputs->registerWriterTarget($document, $source, $job, ['doc_id' => $docId, 'relative_path' => $relative], $targetScope, true);
                $metadata['managed_targets'][$docId] = ['relative_path' => $relative, 'collection' => $scope['collection'],
                    'neo4j_namespace' => $targetScope['graph'] ? $scope['neo4j_namespace'] : null, 'job_id' => $job->job_id, 'task_id' => $job->task_id, 'graph_scope_resolved' => true];
            }
            foreach ($metadata['managed_writers'] ?? [] as $key => $writer) {
                $metadata['managed_writers'][$key]['status'] = 'released';
            }
            $metadata['managed_writer_protocol'] = 1;
            $metadata['managed_target_manifest_complete'] = true;
            $metadata['managed_quiescence_reconciliation'] = ['manifest_sha256' => hash_file('sha256', $path), 'recorded_at' => $this->clock->now()->format(\DateTimeInterface::ATOM)];
            $source->forceFill(['metadata' => $metadata, 'index_status' => 'failed'])->save();
        });
    }

    public function retireLegacyOperation(string $operationId, string $sourceId): void
    {
        $operation = $this->operations->find($operationId);
        $source = $this->sources->findBySourceId($sourceId);
        $request = $source?->metadata['request'] ?? [];
        $owner = ManagedDocumentId::fromRequestMetadata($request['metadata'] ?? $request);
        if (! $operation || $owner?->value !== $operation->document_id || (($operation->attempts['writer_protocol'] ?? null) === 1 && ($operation->attempts['effective_graph_scope'] ?? null) === true)
            || ! $operation->run_id) {
            throw ManagedDeletionException::conflict('Only a legacy operation with an owned source and confirmed run can be retired.');
        }
        $status = $this->bridge->workflowStatus($operation->workflow_id, $operation->run_id);
        if (! in_array($status['status'] ?? null, ['COMPLETED', 'FAILED', 'CANCELED', 'TERMINATED', 'TIMED_OUT'], true)) {
            throw ManagedDeletionException::conflict('Close the legacy deletion workflow before retiring its immutable snapshot.');
        }
        $this->transactions->run(function () use ($operation): void {
            $this->operations->lockDocument($operation->document_id);
            $operation = $this->operations->lock($operation->operation_id);
            foreach ($this->sources->ownedManagedSources($operation->document_id, $operation->dataset_id) as $source) {
                $source = $this->sources->lockBySourceId($source->source_id);
                if (($source->metadata['managed_dispatch']['attempted'] ?? null) === false) {
                    $metadata = $source->metadata;
                    $metadata['managed_writer_gate'] = 'closed';
                    $source->forceFill(['metadata' => $metadata])->save();

                    continue;
                }
                if (($source->metadata['managed_writer_gate'] ?? null) !== 'closed'
                    || empty($source->metadata['managed_quiescence_reconciliation'])
                    || ($source->metadata['managed_target_manifest_complete'] ?? null) !== true) {
                    throw ManagedDeletionException::conflict('Reconcile every owned historical source before retiring the legacy operation.');
                }
                foreach ($source->metadata['managed_writers'] ?? [] as $writer) {
                    if ($writer['status'] !== 'released') {
                        throw ManagedDeletionException::conflict('A historical writer remains uncertain.');
                    }
                }
            }
            $this->operations->save($operation, ['status' => 'abandoned', 'last_error' => 'Legacy snapshot retired after administrative quiescence reconciliation; use a new key to verify full sink cleanup.']);
        });
    }
}
