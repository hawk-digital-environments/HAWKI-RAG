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
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class ManagedDocumentWriterReconciliationService
{
    public function __construct(private IngestionSourceRepository $sources, private ManagedDocumentWriterRepository $jobs,
        private ManagedDocumentOutputRepository $outputs, private PipelineTransactionRepository $transactions,
        private PythonTemporalBridgeClient $bridge,
        private ManagedDocumentDeletionRepository $operations,
        private ManagedWriterReconciliationAssessment $assessment,
        private ClockInterface $clock = new Clock) {}

    /** Assessment never opens a mutation transaction or alters an Eloquent model. */
    public function assess(string $sourceId, string $manifest, bool $confirmedQuiescence = false, ?string $evidenceRef = null): array
    {
        return $this->assessment->inspect($sourceId, $manifest, $confirmedQuiescence, $evidenceRef)['report'];
    }

    public function reconcile(string $sourceId, string $manifest, bool $confirmedQuiescence, ?string $evidenceRef = null): array
    {
        $plan = $this->assessment->inspect($sourceId, $manifest, $confirmedQuiescence, $evidenceRef);
        if (! $plan['report']['eligible']) {
            throw ManagedDeletionException::conflict($plan['report']['reason']);
        }

        return $this->transactions->run(function () use ($sourceId, $manifest, $evidenceRef, $plan): array {
            $current = $this->assessment->snapshot($sourceId, true);
            if ($current !== $plan['snapshot'] || ! $this->assessment->manifestUnchanged($manifest, $plan)) {
                throw ManagedDeletionException::conflict('evidence_changed_during_assessment');
            }
            $source = $this->sources->lockBySourceId($sourceId);
            $metadata = $source->metadata;
            $audit = $metadata['managed_quiescence_reconciliation'] ?? null;
            if (is_array($audit) && ($audit['assessment_sha256'] ?? null) === $plan['fingerprint']
                && ($metadata['managed_writer_gate'] ?? null) === 'closed'
                && ($metadata['managed_writer_protocol'] ?? null) === 1
                && ($metadata['managed_target_manifest_complete'] ?? null) === true) {
                return [...$plan['report'], 'status' => 'already_reconciled', 'dry_run' => false];
            }
            $document = $this->jobs->document($plan['report']['document_id']);
            $job = $this->jobs->job($plan['job']['job_id']);
            foreach ($plan['targets'] as $docId => $target) {
                $scope = ['collection' => $target['collection'], 'graph' => $target['neo4j_namespace'] !== null,
                    'neo4j_namespace' => $target['neo4j_namespace']];
                $this->outputs->registerWriterTarget($document, $source, $job,
                    ['doc_id' => $docId, 'relative_path' => $target['relative_path']], $scope, true);
                $metadata['managed_targets'][$docId] = $target;
            }
            $metadata['managed_writer_gate'] = 'closed';
            $metadata['managed_writer_protocol'] = 1;
            $metadata['managed_target_manifest_complete'] = true;
            // Active/uncertain registrations are refused, never administratively released.
            $metadata['managed_quiescence_reconciliation'] = [
                'source_id' => $sourceId, 'dataset_id' => $source->dataset_id, 'document_id' => $document->document_id,
                'manifest_sha256' => $plan['report']['manifest_sha256'], 'manifest_path' => $plan['report']['manifest_path'],
                'assessment_sha256' => $plan['fingerprint'], 'target_count' => count($plan['targets']),
                'executions' => $plan['report']['temporal'], 'evidence_ref' => $evidenceRef,
                'recorded_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
            ];
            $source->forceFill(['metadata' => $metadata, 'index_status' => 'failed'])->save();

            return [...$plan['report'], 'status' => 'reconciled', 'dry_run' => false];
        });
    }

    public function validateRetirement(string $operationId, string $sourceId): void
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
        if (($status['workflow_id'] ?? null) !== $operation->workflow_id || ($status['run_id'] ?? null) !== $operation->run_id
            || ! in_array($status['status'] ?? null, ['COMPLETED', 'FAILED', 'CANCELED', 'TERMINATED', 'TIMED_OUT'], true)) {
            throw ManagedDeletionException::conflict('Close the legacy deletion workflow before retiring its immutable snapshot.');
        }
        foreach ($this->sources->ownedManagedSources($operation->document_id, $operation->dataset_id) as $owned) {
            if ($owned->source_id === $sourceId || ($owned->metadata['managed_dispatch']['attempted'] ?? null) === false) {
                continue;
            }
            if (($owned->metadata['managed_writer_gate'] ?? null) !== 'closed'
                || empty($owned->metadata['managed_quiescence_reconciliation'])
                || ($owned->metadata['managed_target_manifest_complete'] ?? null) !== true) {
                throw ManagedDeletionException::conflict('Reconcile every other owned source before requesting retirement.');
            }
            foreach ($owned->metadata['managed_writers'] ?? [] as $writer) {
                if (($writer['status'] ?? null) !== 'released') {
                    throw ManagedDeletionException::conflict('A historical writer remains uncertain.');
                }
            }
        }
    }

    public function retireLegacyOperation(string $operationId, string $sourceId): void
    {
        $operation = $this->operations->find($operationId);
        if (! $operation) {
            throw ManagedDeletionException::conflict('Legacy operation is missing.');
        }
        $before = $operation->getAttributes();
        $sourcesBefore = $this->sources->ownedManagedSources($operation->document_id, $operation->dataset_id)->map(fn ($source) => $source->getAttributes())->all();
        $this->validateRetirement($operationId, $sourceId);
        $this->transactions->run(function () use ($operation, $before, $sourcesBefore): void {
            $this->operations->lockDocument($operation->document_id);
            $operation = $this->operations->lock($operation->operation_id);
            $currentSources = $this->sources->ownedManagedSources($operation->document_id, $operation->dataset_id);
            $lockedSources = [];
            foreach ($currentSources as $current) {
                $lockedSources[] = $this->sources->lockBySourceId($current->source_id)->getAttributes();
            }
            if ($operation->getAttributes() !== $before || $lockedSources !== $sourcesBefore) {
                throw ManagedDeletionException::conflict('Legacy retirement evidence changed.');
            }
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
