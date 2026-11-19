<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Services\Pipeline\Clients\PythonTemporalBridgeClient;
use App\Services\Pipeline\Repositories\LegacyDrainRepository;
use App\Services\Pipeline\Repositories\PipelineStageStateRepository;
use App\Services\Pipeline\Repositories\PipelineTransactionRepository;
use App\Services\Pipeline\Repositories\TerminalProjectionRepository;
use App\Services\Pipeline\Values\PipelineStage;
use App\Services\Pipeline\Values\TerminalProjectionTemporalEvidence;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class TerminalProjectionReconciliationService
{
    public function __construct(
        private TerminalProjectionRepository $repository,
        private PipelineTransactionRepository $transactions,
        private PipelineStageStateRepository $stages,
        private PythonTemporalBridgeClient $bridge,
        private LoggerInterface $logger,
        private ClockInterface $clock = new Clock,
    ) {}

    /** Each row is independently revalidated and committed; a refusal never changes ownership. */
    public function reconcile(bool $apply = false, ?string $jobId = null, ?string $evidenceRef = null): array
    {
        if ($apply && ($jobId === null || trim($jobId) === '')) {
            return ['status' => 'refused', 'error' => 'explicit_job_scope_required', 'reconciled' => 0];
        }
        if ($jobId !== null && ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,190}\z/', $jobId)) {
            return ['status' => 'refused', 'error' => 'invalid_job_id', 'reconciled' => 0];
        }
        if ($jobId !== null && ! $this->repository->jobExists($jobId)) {
            return ['status' => 'not_found', 'error' => 'job_not_found', 'job_id' => $jobId, 'reconciled' => 0];
        }
        $reports = [];
        foreach ($this->repository->runningStageIds($jobId) as $id) {
            $snapshot = $this->repository->snapshot($id);
            $temporal = $this->temporalEvidence($snapshot['job']);
            $reason = $this->refusal($snapshot, $temporal, $evidenceRef);
            $report = [
                'stage_id' => $id, 'task_id' => $snapshot['job']['task_id'] ?? null,
                'job_id' => $snapshot['stage']['job_id'] ?? null, 'source_id' => $snapshot['job']['source_id'] ?? null,
                'stage' => $snapshot['stage']['stage'] ?? null, 'stage_status' => $snapshot['stage']['status'] ?? null,
                'task_status' => $snapshot['task']['status'] ?? null, 'job_status' => $snapshot['job']['status'] ?? null,
                'job_index_status' => $snapshot['job']['index_status'] ?? null, 'source_status' => $snapshot['source']['index_status'] ?? null,
                'workflow_id' => $snapshot['job']['temporal_workflow_id'] ?? null, 'run_id' => $snapshot['job']['temporal_run_id'] ?? null,
                'temporal_evidence' => $temporal->value, 'eligible' => $reason === null,
                'reason' => $reason ?? ($temporal === TerminalProjectionTemporalEvidence::NotFound
                    ? 'approved_retention_expiry_and_database_quiescence' : 'terminal_ownership_and_temporal_evidence_verified'),
                'evidence_ref' => $evidenceRef, 'proposed_action' => 'close_running_stage_as_failed',
                'action' => $apply ? 'refused' : 'dry_run',
            ];
            if ($apply && $reason === null) {
                try {
                    $report = $this->transactions->run(function () use ($snapshot, $id, $temporal, $evidenceRef, $report): array {
                        $current = $this->repository->snapshot($id, true);
                        // No evidence obtained before a concurrent retry, writer, or callback may be reused.
                        if ($snapshot !== $current) {
                            return [...$report, 'eligible' => false, 'reason' => 'ownership_changed_during_temporal_inspection'];
                        }
                        $reason = $this->refusal($current, $temporal, $evidenceRef);
                        if ($reason !== null) {
                            return [...$report, 'eligible' => false, 'reason' => $reason];
                        }
                        $at = Carbon::instance($this->clock->now());
                        $this->stages->closeRunning($this->repository->stage($id), $at, [
                            ...$report, 'reason' => 'parent_pipeline_operation_terminated',
                            'recorded_at' => $at->toAtomString(), 'action' => 'reconciled',
                        ]);

                        return [...$report, 'action' => 'reconciled'];
                    });
                } catch (\Throwable $exception) {
                    $this->logger->error('Terminal stage reconciliation transaction failed.', [
                        'stage_id' => $id, 'exception_class' => $exception::class,
                    ]);
                    $report = [...$report, 'eligible' => false, 'action' => 'refused', 'reason' => 'reconciliation_transaction_failed'];
                }
            }
            $reports[] = $report;
        }

        $reconciled = count(array_filter($reports, fn (array $row): bool => $row['action'] === 'reconciled'));
        $refused = count(array_filter($reports, fn (array $row): bool => ! $row['eligible']));

        return ['status' => $refused > 0 ? 'refused' : ($reports === [] ? 'no_candidate' : ($apply ? 'reconciled' : 'candidate')),
            'dry_run' => ! $apply, 'evidence_ref' => $evidenceRef, 'candidates' => $reports,
            'reconciled' => $reconciled, 'refused' => $refused];
    }

    private function temporalEvidence(?array $job): TerminalProjectionTemporalEvidence
    {
        $workflow = $job['temporal_workflow_id'] ?? null;
        $run = $job['temporal_run_id'] ?? null;
        if (! is_string($workflow) || trim($workflow) === '' || ! is_string($run) || trim($run) === '') {
            return TerminalProjectionTemporalEvidence::MissingIdentity;
        }
        try {
            $execution = $this->bridge->workflowStatus($workflow, $run);
        } catch (\Throwable) {
            // Transport/authentication failures and generic HTTP 404s prove nothing.
            return TerminalProjectionTemporalEvidence::Unavailable;
        }
        if (($execution['workflow_id'] ?? null) !== $workflow || ($execution['run_id'] ?? null) !== $run) {
            return TerminalProjectionTemporalEvidence::IdentityConflict;
        }

        return match ($execution['status'] ?? null) {
            'RUNNING' => TerminalProjectionTemporalEvidence::Active,
            'COMPLETED', 'FAILED', 'TIMED_OUT', 'TERMINATED', 'CANCELED' => TerminalProjectionTemporalEvidence::Terminal,
            'NOT_FOUND' => TerminalProjectionTemporalEvidence::NotFound,
            default => TerminalProjectionTemporalEvidence::Unknown,
        };
    }

    private function refusal(array $snapshot, TerminalProjectionTemporalEvidence $temporal, ?string $evidenceRef): ?string
    {
        ['stage' => $stage, 'job' => $job, 'task' => $task, 'source' => $source] = $snapshot;
        if (! $stage || $stage['status'] !== 'running') {
            return 'stage_not_running';
        }
        if (! $job || ! $task || ! $source) {
            return 'missing_job_task_or_source';
        }
        foreach ([[$stage, ['job_id']], [$job, ['job_id', 'task_id', 'source_id', 'temporal_workflow_id', 'temporal_run_id']],
            [$task, ['task_id', 'dataset_id']], [$source, ['source_id', 'task_id', 'dataset_id', 'temporal_workflow_id']]] as [$row, $keys]) {
            foreach ($keys as $key) {
                if (! $this->hasIdentity($row[$key] ?? null)) {
                    return 'missing_ownership_identity';
                }
            }
        }
        $stageMetadata = $this->metadata($stage['metadata'], allowNull: true);
        if ($stageMetadata === null || $this->identityConflict($stageMetadata, $job)) {
            return 'stage_evidence_unknown_or_conflicting';
        }
        foreach (['worker_event', 'temporal'] as $key) {
            if (array_key_exists($key, $stageMetadata) && (! is_array($stageMetadata[$key])
                || $this->identityConflict($stageMetadata[$key], $job))) {
                return 'stage_evidence_unknown_or_conflicting';
            }
        }
        if ($stage['job_id'] !== $job['job_id'] || $task['task_id'] !== $job['task_id']
            || $source['source_id'] !== $job['source_id'] || $source['task_id'] !== $job['task_id']
            || $source['dataset_id'] !== $task['dataset_id'] || PipelineStage::tryFrom($stage['stage']) === null) {
            return 'ownership_identity_conflict';
        }
        if (! in_array($job['status'], LegacyDrainRepository::JOB_TERMINAL, true)) {
            return 'parent_job_not_known_terminal';
        }
        if (! in_array($job['index_status'], LegacyDrainRepository::INDEX_TERMINAL, true)) {
            return 'job_index_not_known_terminal';
        }
        if (! in_array($task['status'], LegacyDrainRepository::TASK_TERMINAL, true)) {
            return 'parent_task_not_known_terminal';
        }
        if (! in_array($source['index_status'], ['deleted', 'failed', 'cancelled'], true)) {
            return 'source_not_known_terminal';
        }
        if (! empty($source['temporal_schedule_id']) || ! empty($job['temporal_schedule_id'])) {
            return 'schedule_may_produce_work';
        }
        $metadata = $this->metadata($source['metadata']);
        if ($metadata === null) {
            return 'unknown_source_metadata';
        }
        if (isset($metadata['url_ingestion_operation_id']) || ($metadata['owner'] ?? null) === 'url') {
            return 'source_is_not_legacy_owned';
        }
        foreach ([$job, ...$snapshot['relatedJobs']] as $related) {
            $jobMetadata = $this->metadata($related['metadata'], allowNull: true);
            if (isset($jobMetadata['url_ingestion_operation_id'])) {
                return 'job_is_not_legacy_owned';
            }
            if ($jobMetadata === null || (array_key_exists('pending_recovery', $jobMetadata)
                && (! is_array($jobMetadata['pending_recovery']) || ($jobMetadata['pending_recovery']['state'] ?? null) !== 'confirmed'))) {
                return 'unresolved_recovery';
            }
            if (! in_array($related['status'], LegacyDrainRepository::JOB_TERMINAL, true)
                || ! in_array($related['index_status'], LegacyDrainRepository::INDEX_TERMINAL, true)) {
                return 'related_job_not_terminal';
            }
            if ($related['task_id'] !== $task['task_id']) {
                return 'related_task_identity_conflict';
            }
            // A successor identity requires separate reconciliation, never inference from an old run.
            if ($related['temporal_workflow_id'] !== $job['temporal_workflow_id'] || $related['temporal_run_id'] !== $job['temporal_run_id']) {
                return 'related_execution_identity_conflict';
            }
            foreach (['temporal', 'pending_recovery'] as $key) {
                if (isset($jobMetadata[$key]) && (! is_array($jobMetadata[$key])
                    || $this->identityConflict($jobMetadata[$key], $job))) {
                    return 'workflow_run_identity_conflict';
                }
            }
        }
        if ($source['temporal_workflow_id'] !== $job['temporal_workflow_id']) {
            return 'workflow_run_identity_conflict';
        }
        foreach (['temporal', 'worker_event'] as $key) {
            if (array_key_exists($key, $metadata) && (! is_array($metadata[$key]) || $this->identityConflict($metadata[$key], $job))) {
                return 'workflow_run_identity_conflict';
            }
        }
        // Source acknowledgements and worker callbacks persist the run here.
        // The source workflow column supplies workflow identity for older rows.
        if (! $this->hasIdentity($metadata['temporal']['run_id'] ?? null)
            && ! $this->hasIdentity($metadata['worker_event']['run_id'] ?? null)) {
            return 'missing_source_run_identity';
        }
        foreach ($snapshot['operations'] as $operation) {
            if (! $this->hasIdentity($operation['dataset_id']) || $operation['dataset_id'] !== $source['dataset_id']) {
                return 'managed_operation_ownership_conflict';
            }
            try {
                $input = json_decode($operation['workflow_input'], true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return 'managed_operation_ownership_conflict';
            }
            if (! is_array($input)
                || (array_key_exists('dataset_id', $input) && $input['dataset_id'] !== $source['dataset_id'])
                || (array_key_exists('managed_document_id', $input) && $input['managed_document_id'] !== $operation['document_id'])) {
                return 'managed_operation_ownership_conflict';
            }
            if (! in_array($operation['purpose'], ['delete', 'update'], true)
                || ($operation['purpose'] === 'delete' && $operation['status'] !== 'completed')
                || ($operation['purpose'] === 'update' && ! in_array($operation['status'], ['continued', 'abandoned'], true))) {
                return 'unresolved_replacement';
            }
        }
        if (array_key_exists('managed_deletion', $metadata)
            && (! is_array($metadata['managed_deletion'])
                || ! in_array($metadata['managed_deletion']['operation_id'] ?? null, array_column($snapshot['operations'], 'operation_id'), true))) {
            return 'missing_associated_managed_operation';
        }
        if ($snapshot['owner'] !== null && ! in_array($snapshot['owner'], array_column($snapshot['documents'], 'document_id'), true)) {
            return 'missing_managed_owner';
        }
        if (count($snapshot['documents']) > 1) {
            return 'conflicting_managed_ownership';
        }
        foreach ($snapshot['documents'] as $document) {
            if ($document['dataset_id'] !== $source['dataset_id']
                || ! in_array($document['status'], ['deleted', 'failed'], true)) {
                return 'managed_owner_not_terminal';
            }
        }
        foreach ($snapshot['outputs'] as $output) {
            if (! in_array($output['document_id'], array_column($snapshot['documents'], 'document_id'), true)) {
                return 'missing_output_owner';
            }
            foreach (['source_id' => $source['source_id'], 'job_id' => $job['job_id'], 'task_id' => $task['task_id']] as $key => $expected) {
                if ($output[$key] !== null && (! $this->hasIdentity($output[$key]) || $output[$key] !== $expected)) {
                    return 'conflicting_output_ownership';
                }
            }
            if (! $this->hasIdentity($output['source_id']) && ! $this->hasIdentity($output['job_id'])) {
                return 'missing_output_execution_identity';
            }
        }
        $writers = $metadata['managed_writers'] ?? [];
        if (array_key_exists('managed_writers', $metadata)
            && (! is_array($metadata['managed_writers']) || ($writers !== [] && array_is_list($writers)))) {
            return 'unknown_writer_registry';
        }
        foreach ($writers as $writer) {
            if (! is_array($writer) || ($writer['status'] ?? null) !== 'released') {
                return 'unreleased_writer';
            }
            if (empty($writer['workflow_id']) || empty($writer['run_id']) || $this->identityConflict($writer, $job)) {
                return 'historical_execution_requires_separate_evidence';
            }
        }
        if (($metadata['managed_dispatch']['attempted'] ?? null) !== false
            && (($metadata['managed_writer_protocol'] ?? null) !== 1
                || ($metadata['managed_target_manifest_complete'] ?? null) !== true
                || ($metadata['managed_writer_gate'] ?? null) !== 'closed')) {
            return 'unproven_writer_quiescence';
        }
        $history = $metadata['managed_writer_history'] ?? [];
        if (! is_array($history)) {
            return 'unknown_writer_history';
        }
        foreach ($history as $execution) {
            if (! is_array($execution) || empty($execution['workflow_id']) || empty($execution['run_id']) || $this->identityConflict($execution, $job)) {
                return 'historical_execution_requires_separate_evidence';
            }
        }
        if ($temporal === TerminalProjectionTemporalEvidence::NotFound) {
            return $evidenceRef === null || ! preg_match('/\A[\x21-\x7E]{1,512}\z/', $evidenceRef)
                ? 'approved_retention_evidence_ref_required' : null;
        }

        return $temporal === TerminalProjectionTemporalEvidence::Terminal ? null : 'temporal_'.$temporal->value;
    }

    private function identityConflict(array $identity, array $job): bool
    {
        return (array_key_exists('workflow_id', $identity) && $identity['workflow_id'] !== $job['temporal_workflow_id'])
            || (array_key_exists('run_id', $identity) && $identity['run_id'] !== $job['temporal_run_id']);
    }

    private function hasIdentity(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function metadata(?string $value, bool $allowNull = false): ?array
    {
        if ($value === null) {
            return $allowNull ? [] : null;
        }
        if (! str_starts_with(ltrim($value), '{')) {
            return null;
        }
        try {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }
}
