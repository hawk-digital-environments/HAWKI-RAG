<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\Repositories\ManagedDocumentDeletionRepository;
use App\Services\Document\Repositories\ManagedWriterReconciliationRepository;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use Illuminate\Contracts\Config\Repository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

/** Read-only proof for exact retained deletion input; never creates or repairs evidence. */
readonly class ManagedDeletionWriterProofService
{
    public function __construct(private ManagedDocumentDeletionRepository $operations,
        private IngestionSourceRepository $sources, private Repository $config,
        private ManagedWriterReconciliationRepository $snapshots,
        private ClockInterface $clock = new Clock) {}

    public function prove(array $request, string $raw): array
    {
        $operation = $this->operations->find($request['operation_id']);
        if (! $operation || $operation->document_id !== $request['document_id'] || $operation->dataset_id !== $request['dataset_id']
            || $operation->workflow_id !== $request['deletion_workflow_id'] || $operation->run_id !== $request['deletion_run_id']
            || ! in_array($operation->status, ['pending', 'failed'], true)
            || ($operation->attempts['writer_protocol'] ?? null) !== 1 || ($operation->attempts['effective_graph_scope'] ?? null) !== true
            || ($operation->attempts['dispatch_sequence'] ?? 0) < 1) {
            throw ManagedDeletionException::conflict('deletion_identity_or_barrier_unproven');
        }
        $targets = $operation->workflow_input['targets'] ?? [];
        array_walk($targets, static function (array &$target): void {
            ksort($target);
        });
        if (hash('sha256', json_encode($targets, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== $request['targets_sha256']) {
            throw ManagedDeletionException::conflict('immutable_target_scope_changed');
        }
        $identity = ['workflow_id' => $request['writer_workflow_id'], 'run_id' => $request['writer_run_id']];
        if (! in_array($identity, $operation->workflow_input['writers'] ?? [], true)) {
            throw ManagedDeletionException::conflict('writer_not_in_immutable_operation');
        }
        $proven = false;
        $proofs = [];
        foreach ($this->sources->ownedManagedSources($operation->document_id, $operation->dataset_id) as $source) {
            $metadata = $source->metadata;
            $history = $metadata['managed_writer_history'] ?? [];
            $history[] = ['workflow_id' => $source->temporal_workflow_id, 'run_id' => $metadata['temporal']['run_id'] ?? $metadata['worker_event']['run_id'] ?? null];
            if (! in_array($identity, array_values($history), true)) {
                continue;
            }
            if (($metadata['managed_writer_gate'] ?? null) !== 'closed' || ($metadata['managed_writer_protocol'] ?? null) !== 1
                || ($metadata['managed_target_manifest_complete'] ?? null) !== true
                || ($metadata['managed_deletion']['operation_id'] ?? null) !== $operation->operation_id
                || ! array_key_exists($source->source_id, $operation->attempts['source_states'] ?? [])) {
                throw ManagedDeletionException::conflict('writer_barrier_unproven');
            }
            $writers = $metadata['managed_writers'] ?? [];
            if (! is_array($writers) || $writers !== [] && array_is_list($writers)) {
                throw ManagedDeletionException::conflict('writer_registry_unproven');
            }
            $registered = false;
            foreach ($writers as $writer) {
                if (! is_array($writer) || ($writer['status'] ?? null) !== 'released'
                    || ! is_string($writer['activity_id'] ?? null) || $writer['activity_id'] === ''
                    || ! is_int($writer['attempt'] ?? null) || $writer['attempt'] < 1
                    || ! is_string($writer['job_id'] ?? null)) {
                    throw ManagedDeletionException::conflict('writer_not_released');
                }
                $registered = $registered || ($writer['workflow_id'] ?? null) === $identity['workflow_id'] && ($writer['run_id'] ?? null) === $identity['run_id'];
            }
            $audit = $metadata['managed_quiescence_reconciliation'] ?? [];
            $execution = $audit['executions'][$identity['workflow_id'].'|'.$identity['run_id']] ?? [];
            $approved = ($audit['source_id'] ?? null) === $source->source_id && ($audit['dataset_id'] ?? null) === $source->dataset_id
                && ($audit['document_id'] ?? null) === $operation->document_id && preg_match('/\A[a-f0-9]{64}\z/', $audit['manifest_sha256'] ?? '')
                && ($execution['workflow_id'] ?? null) === $identity['workflow_id'] && ($execution['run_id'] ?? null) === $identity['run_id']
                && in_array($execution['classification'] ?? null, ['terminal', 'not_found_requires_retention_approval'], true)
                && preg_match('/\A[\x21-\x7E]{1,512}\z/', $audit['evidence_ref'] ?? '');
            if (! $registered && ! $approved) {
                throw ManagedDeletionException::conflict('approved_writer_reconciliation_required');
            }
            $targets = [];
            foreach ($operation->workflow_input['targets'] as $target) {
                if ($target['source_id'] === $source->source_id) {
                    $targets[$target['doc_id']] = $target;
                }
            }
            if (count($targets) !== count($metadata['managed_targets'] ?? [])) {
                throw ManagedDeletionException::conflict('immutable_manifest_scope_changed');
            }
            foreach ($metadata['managed_targets'] ?? [] as $docId => $target) {
                if (! isset($targets[$docId]) || $targets[$docId]['collection'] !== $target['collection']
                    || $targets[$docId]['neo4j_namespace'] !== $target['neo4j_namespace']) {
                    throw ManagedDeletionException::conflict('immutable_manifest_scope_changed');
                }
            }
            $snapshot = $this->snapshots->snapshot($source->source_id);
            if ($snapshot['owner'] !== $operation->document_id || count($snapshot['documents']) !== 1
                || $snapshot['documents'][0]['dataset_id'] !== $operation->dataset_id
                || json_decode($snapshot['source']['metadata'] ?? '{}', true) !== $metadata) {
                throw ManagedDeletionException::conflict('writer_ownership_changed');
            }
            foreach ($snapshot['outputs'] as $output) {
                if ($output['source_id'] !== $source->source_id) {
                    continue;
                }
                $target = $targets[$output['bridge_document_id']] ?? null;
                if (! $target || $output['document_id'] !== $operation->document_id || (int) $output['id'] !== $target['output_id']
                    || $output['qdrant_collection'] !== $target['collection'] || $output['neo4j_namespace'] !== $target['neo4j_namespace']) {
                    throw ManagedDeletionException::conflict('immutable_output_scope_changed');
                }
            }
            foreach ($writers as $writer) {
                $jobs = array_values(array_filter($snapshot['jobs'], fn (array $job) => $job['job_id'] === $writer['job_id']));
                if (count($jobs) !== 1 || $jobs[0]['temporal_workflow_id'] !== ($writer['workflow_id'] ?? null)
                    || $jobs[0]['temporal_run_id'] !== ($writer['run_id'] ?? null)) {
                    throw ManagedDeletionException::conflict('registered_writer_identity_unproven');
                }
            }
            foreach ($snapshot['jobs'] as $job) {
                $jobMetadata = json_decode($job['metadata'] ?? '{}', true);
                if (! is_array($jobMetadata) || ! in_array($job['status'], ['completed', 'failed', 'skipped'], true)
                    || array_key_exists('pending_recovery', $jobMetadata) && ($jobMetadata['pending_recovery']['state'] ?? null) !== 'confirmed') {
                    throw ManagedDeletionException::conflict('unresolved_recovery');
                }
            }
            foreach ($snapshot['operations'] as $related) {
                if ($related['purpose'] === 'update' && ! in_array($related['status'], ['continued', 'abandoned'], true)) {
                    throw ManagedDeletionException::conflict('unresolved_replacement');
                }
            }
            $proven = true;
            $proofs[] = ['source_id' => $source->source_id, 'kind' => $registered ? 'released' : 'approved_reconciliation'];
        }
        if (! $proven) {
            throw ManagedDeletionException::conflict('writer_source_not_proven');
        }
        if ($this->operations->find($operation->operation_id)?->getAttributes() !== $operation->getAttributes()) {
            throw ManagedDeletionException::conflict('operation_changed_during_proof');
        }
        $proof = ['request_sha256' => hash('sha256', $raw), 'dispatch_sequence' => $operation->attempts['dispatch_sequence'],
            'verified' => true, 'sources' => $proofs, 'issued_at' => $this->clock->now()->getTimestamp()];
        $body = json_encode($proof, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return ['proof' => $body, 'signature' => hash_hmac('sha256', "hawki-deletion-writer-proof-v1\n".$body, (string) $this->config->get('temporal.callbacks.secret'))];
    }
}
