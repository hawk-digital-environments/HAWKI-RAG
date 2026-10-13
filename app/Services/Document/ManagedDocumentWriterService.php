<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\ManagedDocument;
use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\Repositories\ManagedDocumentDeletionRepository;
use App\Services\Document\Repositories\ManagedDocumentOutputRepository;
use App\Services\Document\Repositories\ManagedDocumentWriterRepository;
use App\Services\Document\Values\ManagedDocumentId;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use App\Services\Pipeline\Repositories\PipelineTransactionRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ManagedDocumentWriterService
{
    public function __construct(
        private ManagedDocumentWriterRepository $jobs,
        private IngestionSourceRepository $sources,
        private ManagedDocumentOutputRepository $outputs,
        private PipelineTransactionRepository $transactions,
        private ManagedDocumentDeletionRepository $operations,
    ) {}

    public function record(array $input): array
    {
        return $this->transactions->run(function () use ($input): array {
            $lockedDocument = null;
            $grants = [];
            if ($input['action'] === 'acquire') {
                $identity = $this->sources->findBySourceId($input['source_id']);
                $request = $identity?->metadata['request'] ?? [];
                $owner = ManagedDocumentId::fromRequestMetadata($request['metadata'] ?? $request);
                // Output inserts take a document FK lock. Acquire it before job/source
                // locks to match deletion; release never needs this document lock.
                $lockedDocument = $owner ? $this->jobs->lockDocument($owner->value) : null;
                if (! $lockedDocument || $lockedDocument->deleted_at !== null) {
                    throw ManagedDeletionException::conflict('Managed writer has no live committed owner.');
                }
            }
            $job = $this->jobs->lockJob($input['job_id']);
            $source = $this->sources->lockBySourceId($input['source_id']);
            if (! $job || ! $source || $job->source_id !== $source->source_id || $job->task_id !== $source->task_id
                || $job->temporal_workflow_id !== $input['workflow_id']
                || $source->temporal_workflow_id !== $input['workflow_id']) {
                throw ManagedDeletionException::conflict('Managed writer does not match a committed ingestion execution.');
            }
            if ($job->temporal_run_id && $job->temporal_run_id !== $input['run_id']) {
                throw ManagedDeletionException::conflict('Managed writer run does not match the committed execution.');
            }
            $metadata = $source->metadata ?? [];
            $request = $metadata['request'] ?? [];
            $owner = ManagedDocumentId::fromRequestMetadata($request['metadata'] ?? $request);
            $document = $owner ? $this->jobs->document($owner->value) : null;
            if (! $document || $document->dataset_id !== $source->dataset_id
                || ($lockedDocument && $lockedDocument->document_id !== $document->document_id)) {
                throw ManagedDeletionException::conflict('Managed writer ownership cannot be established.');
            }
            $key = hash('sha256', implode('|', [$input['workflow_id'], $input['run_id'], $input['activity_id'], $input['attempt']]));
            $writers = $metadata['managed_writers'] ?? [];
            $previous = $writers[$key] ?? null;
            if ($input['action'] === 'acquire') {
                if ($document->status === ManagedDocument::STATUS_DELETING) {
                    $operation = $this->operations->blocking($document->document_id);
                    // Only the durably prepared replacement may start after verified cleanup.
                    if ($operation?->purpose !== 'update' || $operation->status !== 'completed'
                        || ($operation->attempts['effective_graph_scope'] ?? null) !== true
                        || ($operation->replacement['payload']['source_id'] ?? null) !== $source->source_id) {
                        throw ManagedDeletionException::conflict('Managed document deletion prevents writer acquisition.');
                    }
                }
                if (isset($metadata['managed_deletion']) || ($metadata['managed_writer_gate'] ?? null) === 'closed'
                    || ($previous && $previous['status'] !== 'active')) {
                    throw ManagedDeletionException::conflict('Managed source is fenced; this writer may not mutate sinks.');
                }
                if (count($writers) >= 1000 && ! $previous) {
                    throw ManagedDeletionException::conflict('Managed writer history requires reconciliation before further attempts.');
                }
                foreach ($writers as $id => $writer) {
                    if ($id !== $key && $writer['status'] !== 'released') {
                        throw ManagedDeletionException::conflict('Another writer is active or uncertain; reconcile it before recovery.');
                    }
                }
                $scope = $metadata['managed_dispatch']['input']['ingestion'] ?? null;
                if (! is_array($scope) || empty($scope['collection']) || (! empty($scope['graph']) && empty($scope['neo4j_namespace']))) {
                    throw ManagedDeletionException::conflict('Managed writer has no immutable historical sink scope.');
                }
                $writers[$key] = ['workflow_id' => $input['workflow_id'], 'run_id' => $input['run_id'],
                    'activity_id' => $input['activity_id'], 'attempt' => $input['attempt'], 'job_id' => $input['job_id'], 'status' => 'active'];
                foreach ($input['targets'] ?? [] as $target) {
                    // Effective graph indexing may be required by converter passthrough.
                    // The worker can request graph publication only in the frozen namespace.
                    $targetScope = $scope;
                    $targetScope['graph'] = (bool) ($scope['graph'] ?? false) || (bool) ($target['graph'] ?? false)
                        || ! empty($metadata['managed_targets'][$target['doc_id']]['neo4j_namespace']);
                    if ($targetScope['graph'] && empty($scope['neo4j_namespace'])) {
                        throw ManagedDeletionException::conflict('Effective graph indexing has no trusted namespace.');
                    }
                    $path = $target['relative_path'];
                    if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || array_intersect(explode('/', $path), ['', '.', '..']) !== []
                        || $target['doc_id'] !== 'doc_'.substr(hash('sha256', $source->source_id.'|'.$path), 0, 40)) {
                        throw ManagedDeletionException::conflict('Managed writer document identity is invalid.');
                    }
                    $this->outputs->registerWriterTarget($document, $source, $job, $target, $targetScope);
                    $namespace = $targetScope['graph'] ? $scope['neo4j_namespace'] : null;
                    $metadata['managed_targets'][$target['doc_id']] = ['relative_path' => $path, 'collection' => $scope['collection'], 'neo4j_namespace' => $namespace, 'job_id' => $job->job_id, 'task_id' => $job->task_id,
                        'graph_scope_resolved' => array_key_exists('graph', $target) || ! empty($scope['graph']) || empty($scope['neo4j_namespace'])
                            || (($metadata['managed_targets'][$target['doc_id']]['graph_scope_resolved'] ?? null) === true)];
                    $grants[] = ['doc_id' => $target['doc_id'], 'source_id' => $source->source_id, 'dataset_id' => $source->dataset_id, 'collection' => $scope['collection'], 'neo4j_namespace' => $namespace];
                }
                if (! $job->temporal_run_id) {
                    $this->jobs->adoptRun($job, $input['run_id']);
                }
                $metadata['temporal']['workflow_id'] = $input['workflow_id'];
                $metadata['temporal']['run_id'] = $input['run_id'];
            } else {
                if (! $previous) {
                    throw ManagedDeletionException::conflict('Managed writer must acquire before reporting completion.');
                }
                // Uncertainty is never cleared by a late success; operator reconciliation is required.
                if ($previous['status'] !== 'uncertain') {
                    $writers[$key]['status'] = $input['action'] === 'release' ? 'released' : 'uncertain';
                }
                if ($input['action'] === 'release' && $writers[$key]['status'] === 'released') {
                    $metadata['managed_target_manifest_complete'] = true;
                }
            }
            $metadata['managed_writers'] = $writers;
            $metadata['managed_writer_history'][$input['workflow_id'].'|'.$input['run_id']] = ['workflow_id' => $input['workflow_id'], 'run_id' => $input['run_id']];
            unset($metadata['managed_writer_history'][$input['workflow_id'].'|unknown']);
            $metadata['managed_writer_protocol'] = 1;
            $source->forceFill(['metadata' => $metadata])->save();

            return ['success' => true, 'status' => $writers[$key]['status'], 'targets' => $grants];
        });
    }
}
