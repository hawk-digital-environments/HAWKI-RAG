<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Models\IngestionSource;
use App\Models\ManagedDocument;
use App\Models\ManagedDocumentDeletion;
use App\Models\ManagedDocumentOutput;
use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Models\PipelineTask;
use App\Services\Pipeline\LegacyDrainStatusService;
use App\Services\Pipeline\Repositories\TerminalProjectionRepository;
use App\Services\Pipeline\Tasks\PipelineTaskCancellationService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TerminalProjectionReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seven_historical_stages_require_approval_and_reduce_actual_drain_counts_idempotently(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->fixture((string) $i);
        }
        $parents = [DB::table('pipeline_jobs')->get(), DB::table('pipeline_tasks')->get(), DB::table('ingestion_sources')->get()];
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $report = $this->command([], 1);
        $this->assertCount(7, $report['candidates']);
        $this->assertSame(7, $this->drainCount());
        foreach ($report['candidates'] as $row) {
            $this->assertSame('not_found_requires_retention_approval', $row['temporal_evidence']);
            $this->assertSame('approved_retention_evidence_ref_required', $row['reason']);
            $this->assertSame('dry_run', $row['action']);
        }
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|drop|alter|create|truncate)\b/i', $sql);
        }
        for ($i = 0; $i < 7; $i++) {
            $this->command(['--apply' => true, '--job-id' => 'job'.$i], 1);
            $this->assertSame(7 - $i, $this->drainCount());
            $report = $this->command(['--apply' => true, '--job-id' => 'job'.$i, '--evidence-ref' => 'audit:epoch15b-approved-7']);
            $this->assertSame(1, $report['reconciled']);
            $stage = PipelineStageState::query()->where('job_id', 'job'.$i)->firstOrFail();
            $this->assertSame('failed', $stage->status);
            $this->assertSame('audit:epoch15b-approved-7', $stage->metadata['terminal_projection_reconciliation']['evidence_ref']);
            $this->assertSame('parent_pipeline_operation_terminated', $stage->metadata['terminal_projection_reconciliation']['reason']);
            $before = $stage->getAttributes();
            $this->assertSame(0, $this->command(['--apply' => true, '--job-id' => 'job'.$i, '--evidence-ref' => 'audit:epoch15b-approved-7'])['reconciled']);
            $this->assertSame($before, $stage->fresh()->getAttributes());
        }
        $this->assertSame(0, $this->drainCount());
        $this->assertEquals($parents, [DB::table('pipeline_jobs')->get(), DB::table('pipeline_tasks')->get(), DB::table('ingestion_sources')->get()]);
    }

    #[TestWith(['RUNNING', 200, 'temporal_active'])]
    #[TestWith(['FAILED', 502, 'temporal_unavailable'])]
    #[TestWith(['NOT_FOUND', 404, 'temporal_unavailable'])]
    #[TestWith(['FUTURE_STATUS', 200, 'temporal_unknown'])]
    #[TestWith(['CONTINUED_AS_NEW', 200, 'temporal_unknown'])]
    public function test_temporal_failures_refuse_even_with_reference(string $status, int $http, string $reason): void
    {
        $this->fixture();
        $this->fakeTemporal($status, $http);
        $this->assertRefused($reason);
    }

    public function test_outage_without_reference_is_refused(): void
    {
        $this->fixture();
        $this->fakeTemporal('FAILED', 503);
        $row = $this->command(['--apply' => true, '--job-id' => 'job'], 1)['candidates'][0];
        $this->assertSame('temporal_unavailable', $row['reason']);
        $this->assertSame(1, $this->drainCount());
    }

    #[TestWith(['COMPLETED'])]
    #[TestWith(['FAILED'])]
    #[TestWith(['CANCELED'])]
    #[TestWith(['TERMINATED'])]
    #[TestWith(['TIMED_OUT'])]
    public function test_proven_terminal_run_needs_no_retention_override(string $status): void
    {
        $this->fixture();
        $this->fakeTemporal($status);
        $this->assertSame(1, $this->command(['--apply' => true, '--job-id' => 'job'])['reconciled']);
    }

    #[TestWith(['job', 'status', 'running', 'parent_job_not_known_terminal'])]
    #[TestWith(['job', 'status', 'queued', 'parent_job_not_known_terminal'])]
    #[TestWith(['job', 'status', 'future', 'parent_job_not_known_terminal'])]
    #[TestWith(['job', 'index_status', 'running', 'job_index_not_known_terminal'])]
    #[TestWith(['job', 'index_status', null, 'job_index_not_known_terminal'])]
    #[TestWith(['job', 'index_status', 'future', 'job_index_not_known_terminal'])]
    #[TestWith(['task', 'status', 'running', 'parent_task_not_known_terminal'])]
    #[TestWith(['task', 'status', 'pending', 'parent_task_not_known_terminal'])]
    #[TestWith(['task', 'status', 'future', 'parent_task_not_known_terminal'])]
    #[TestWith(['source', 'index_status', 'deleting', 'source_not_known_terminal'])]
    #[TestWith(['source', 'index_status', 'running', 'source_not_known_terminal'])]
    #[TestWith(['source', 'index_status', 'active', 'source_not_known_terminal'])]
    #[TestWith(['source', 'index_status', 'indexing', 'source_not_known_terminal'])]
    #[TestWith(['source', 'index_status', 'ready', 'source_not_known_terminal'])]
    #[TestWith(['source', 'index_status', 'future', 'source_not_known_terminal'])]
    #[TestWith(['source', 'temporal_schedule_id', 'schedule', 'schedule_may_produce_work'])]
    #[TestWith(['source', 'temporal_workflow_id', 'different', 'workflow_run_identity_conflict'])]
    #[TestWith(['job', 'temporal_run_id', null, 'missing_ownership_identity'])]
    public function test_active_unknown_or_conflicting_database_state_refuses(string $model, string $field, ?string $value, string $reason): void
    {
        $models = $this->fixture();
        $models[$model]->forceFill([$field => $value])->save();
        $this->assertRefused($reason);
    }

    #[TestWith(['managed_writers', ['writer' => ['status' => 'active']], 'unreleased_writer'])]
    #[TestWith(['managed_writers', ['writer' => ['status' => 'uncertain']], 'unreleased_writer'])]
    #[TestWith(['managed_writers', null, 'unknown_writer_registry'])]
    #[TestWith(['managed_writers', [['status' => 'released']], 'unknown_writer_registry'])]
    #[TestWith(['managed_writer_protocol', 0, 'unproven_writer_quiescence'])]
    #[TestWith(['managed_target_manifest_complete', false, 'unproven_writer_quiescence'])]
    #[TestWith(['managed_writer_gate', 'open', 'unproven_writer_quiescence'])]
    #[TestWith(['temporal', ['run_id' => 'different'], 'workflow_run_identity_conflict'])]
    #[TestWith(['managed_writer_history', [['run_id' => 'different']], 'historical_execution_requires_separate_evidence'])]
    public function test_writer_and_identity_evidence_fail_closed(string $key, mixed $value, string $reason): void
    {
        $models = $this->fixture();
        $metadata = $models['source']->metadata;
        $metadata[$key] = $value;
        $models['source']->forceFill(['metadata' => $metadata])->save();
        $this->assertRefused($reason);
    }

    #[TestWith(['reserved'])]
    #[TestWith(['dispatching'])]
    #[TestWith(['uncertain'])]
    #[TestWith(['future'])]
    public function test_unresolved_recovery_refuses(string $state): void
    {
        $models = $this->fixture();
        $models['job']->forceFill(['metadata' => ['pending_recovery' => ['state' => $state]]])->save();
        $this->assertRefused('unresolved_recovery');
    }

    #[TestWith(['completed'])]
    #[TestWith(['failed'])]
    #[TestWith(['preparing'])]
    public function test_unresolved_managed_replacement_refuses(string $status): void
    {
        $models = $this->fixture();
        ManagedDocument::query()->create(['document_id' => 'adoc_test', 'dataset_id' => 'legacy', 'status' => 'deleted', 'latest_source_id' => 'source']);
        ManagedDocumentDeletion::query()->create(['operation_id' => 'op', 'document_id' => 'adoc_test', 'dataset_id' => 'legacy',
            'purpose' => 'update', 'status' => $status, 'workflow_id' => 'delete-workflow', 'workflow_input' => []]);
        $this->assertRefused('unresolved_replacement');
    }

    #[TestWith(['job'])]
    #[TestWith(['task'])]
    #[TestWith(['source'])]
    public function test_missing_ownership_refuses(string $missing): void
    {
        $models = $this->fixture();
        // Simulate historic orphan references without deleting FK parents.
        if ($missing === 'job') {
            Schema::table('pipeline_stage_states', fn (Blueprint $table) => $table->unsignedBigInteger('pipeline_job_id')->nullable()->change());
        }
        match ($missing) {
            'job' => $models['stage']->forceFill(['pipeline_job_id' => null])->save(),
            'task' => $models['job']->forceFill(['task_id' => 'missing'])->save(),
            'source' => $models['job']->forceFill(['source_id' => 'missing'])->save(),
        };
        $this->assertRefused('missing_job_task_or_source');
    }

    public function test_response_identity_mismatch_refuses(): void
    {
        $this->fixture();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['workflow_id' => 'wrong', 'run_id' => 'run', 'status' => 'FAILED'])]);
        $this->assertRefused('temporal_identity_conflict');
    }

    public function test_concurrent_recovery_invalidates_previously_obtained_evidence(): void
    {
        $models = $this->fixture();
        Http::swap(new Factory);
        Http::fake(function () use ($models) {
            $models['job']->forceFill(['metadata' => ['pending_recovery' => ['state' => 'reserved']]])->save();

            return Http::response(['workflow_id' => 'workflow', 'run_id' => 'run', 'status' => 'FAILED']);
        });
        $this->assertRefused('ownership_changed_during_temporal_inspection');
    }

    public function test_terminal_stage_is_never_rewritten(): void
    {
        $models = $this->fixture();
        $models['stage']->forceFill(['status' => 'completed', 'errors' => ['original']])->save();
        $before = $models['stage']->fresh()->getAttributes();
        $this->assertSame([], $this->command(['--apply' => true, '--job-id' => 'job', '--evidence-ref' => 'audit:approved'])['candidates']);
        $this->assertSame($before, $models['stage']->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_apply_requires_explicit_job_scope(): void
    {
        $this->fixture();
        $report = $this->command(['--apply' => true, '--evidence-ref' => 'audit:approved'], 1);
        $this->assertArrayHasKey('error', $report);
        $this->assertSame(1, $this->drainCount());
        Http::assertNothingSent();
    }

    public function test_dry_run_with_approved_evidence_remains_read_only_and_command_is_registered(): void
    {
        $this->fixture();
        $before = DB::table('pipeline_stage_states')->get();
        $report = $this->command(['--job-id' => 'job', '--evidence-ref' => 'audit:approved']);
        $this->assertSame('candidate', $report['status']);
        $this->assertTrue($report['candidates'][0]['eligible']);
        $this->assertSame('dry_run', $report['candidates'][0]['action']);
        $this->assertSame(0, $report['reconciled']);
        $this->assertEquals($before, DB::table('pipeline_stage_states')->get());
        $this->assertSame(0, Artisan::call('list', ['--raw' => true]));
        $this->assertStringContainsString('pipeline:reconcile-terminal-projections', Artisan::output());
    }

    public function test_apply_cannot_change_another_jobs_stage(): void
    {
        $this->fixture();
        $other = $this->fixture('other');
        $before = $other['stage']->fresh()->getAttributes();
        $this->assertSame(1, $this->command(['--apply' => true, '--job-id' => 'job', '--evidence-ref' => 'audit:approved'])['reconciled']);
        $this->assertSame($before, $other['stage']->fresh()->getAttributes());
    }

    public function test_stage_worker_event_identity_mismatch_refuses(): void
    {
        $models = $this->fixture();
        $models['stage']->forceFill(['metadata' => ['worker_event' => ['run_id' => 'different']]])->save();
        $this->assertRefused('stage_evidence_unknown_or_conflicting');
    }

    #[TestWith(['ingestion_sources', 'unknown_source_metadata'])]
    #[TestWith(['pipeline_jobs', 'unresolved_recovery'])]
    #[TestWith(['pipeline_stage_states', 'stage_evidence_unknown_or_conflicting'])]
    public function test_malformed_metadata_refuses(string $table, string $reason): void
    {
        $this->fixture();
        DB::table($table)->update(['metadata' => 'malformed-json']);
        $this->assertRefused($reason);
    }

    public function test_active_related_job_refuses(): void
    {
        $this->fixture();
        PipelineJob::query()->create(['job_id' => 'replacement', 'task_id' => 'task', 'source_id' => 'source',
            'status' => 'running', 'index_status' => 'running', 'temporal_workflow_id' => 'workflow', 'temporal_run_id' => 'run']);
        $this->assertRefused('related_job_not_terminal');
    }

    public function test_missing_managed_owner_refuses(): void
    {
        $models = $this->fixture();
        $metadata = $models['source']->metadata;
        $metadata['request']['metadata']['managed_document_id'] = 'adoc_missing';
        $models['source']->forceFill(['metadata' => $metadata])->save();
        $this->assertRefused('missing_managed_owner');
    }

    public function test_missing_explicit_owner_cannot_be_hidden_by_another_document_pointing_to_source(): void
    {
        $models = $this->fixture();
        ManagedDocument::query()->create(['document_id' => 'adoc_other', 'dataset_id' => 'legacy', 'status' => 'deleted', 'latest_source_id' => 'source']);
        $metadata = $models['source']->metadata;
        $metadata['request']['metadata']['managed_document_id'] = 'adoc_missing';
        $models['source']->forceFill(['metadata' => $metadata])->save();
        $this->assertRefused('missing_managed_owner');
    }

    public function test_managed_operation_reference_requires_existing_ownership_evidence(): void
    {
        $models = $this->fixture();
        $metadata = $models['source']->metadata;
        $metadata['managed_deletion'] = ['operation_id' => 'missing-operation', 'status' => 'deleted'];
        $models['source']->forceFill(['metadata' => $metadata])->save();
        $this->assertRefused('missing_associated_managed_operation');
    }

    public function test_python_source_ownership_is_not_repaired_as_legacy(): void
    {
        $models = $this->fixture();
        $metadata = $models['source']->metadata;
        $metadata['owner'] = 'url';
        $models['source']->forceFill(['metadata' => $metadata])->save();
        $this->assertRefused('source_is_not_legacy_owned');
    }

    public function test_reconciliation_write_failure_is_rolled_back_and_reported(): void
    {
        $this->fixture();
        DB::unprepared("CREATE TRIGGER fail_stage BEFORE UPDATE ON pipeline_stage_states BEGIN SELECT RAISE(ABORT, 'stage_write_failed'); END");
        $this->assertRefused('reconciliation_transaction_failed');
        $this->assertSame(1, $this->drainCount());
    }

    public function test_missing_required_schema_produces_a_safe_inspection_failure(): void
    {
        $this->fixture();
        Schema::table('ingestion_sources', fn (Blueprint $table) => $table->dropColumn('metadata'));
        // A missing evidence column is unknown, never a reason to repair.
        $report = $this->command(['--apply' => true, '--job-id' => 'job', '--evidence-ref' => 'audit:approved'], 1);
        $this->assertSame('inspection_failed_review_prior_rows_before_retry', $report['error']);
        $this->assertDatabaseHas('pipeline_stage_states', ['job_id' => 'job', 'status' => 'running']);
    }

    public function test_future_cancellation_closes_running_stages_and_preserves_terminal_stages(): void
    {
        $models = $this->fixture();
        $models['job']->forceFill(['status' => 'running', 'index_status' => 'running'])->save();
        $models['task']->forceFill(['status' => 'running'])->save();
        $completed = PipelineStageState::query()->create(['pipeline_job_id' => $models['job']->id, 'job_id' => 'job', 'stage' => 'convert', 'status' => 'completed']);
        $before = $completed->fresh()->getAttributes();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['ok' => true])]);
        app(PipelineTaskCancellationService::class)->cancel('task');
        $this->assertSame('failed', $models['job']->fresh()->status);
        $this->assertSame('cancelled', $models['job']->fresh()->index_status);
        $this->assertSame('failed', $models['stage']->fresh()->status);
        $this->assertSame('parent_pipeline_cancellation', $models['stage']->fresh()->metadata['terminal_projection_reconciliation']['reason']);
        $this->assertSame($before, $completed->fresh()->getAttributes());
        $snapshot = $models['stage']->fresh()->getAttributes();
        app(PipelineTaskCancellationService::class)->cancel('task');
        $this->assertSame($snapshot, $models['stage']->fresh()->getAttributes());
    }

    public function test_stage_write_failure_rolls_back_job_cancellation(): void
    {
        $models = $this->fixture();
        $models['job']->forceFill(['status' => 'running', 'index_status' => 'running'])->save();
        $models['task']->forceFill(['status' => 'running'])->save();
        $before = $models['job']->fresh()->getAttributes();
        $second = PipelineStageState::query()->create(['pipeline_job_id' => $models['job']->id,
            'job_id' => 'job', 'stage' => 'scrape', 'status' => 'running']);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['ok' => true])]);
        DB::unprepared("CREATE TRIGGER fail_stage BEFORE UPDATE ON pipeline_stage_states WHEN OLD.stage = 'scrape' BEGIN SELECT RAISE(ABORT, 'stage_write_failed'); END");
        try {
            app(PipelineTaskCancellationService::class)->cancel('task');
            $this->fail('Expected a stage write failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('stage_write_failed', $exception->getMessage());
        }
        $this->assertSame($before, $models['job']->fresh()->getAttributes());
        $this->assertSame('running', $models['stage']->fresh()->status);
        $this->assertSame('running', $second->fresh()->status);
    }

    #[TestWith([null, null, 'missing_ownership_identity'])]
    #[TestWith(['', '', 'missing_ownership_identity'])]
    #[TestWith([' ', ' ', 'missing_ownership_identity'])]
    #[TestWith([null, 'legacy', 'missing_ownership_identity'])]
    #[TestWith(['legacy', null, 'missing_ownership_identity'])]
    #[TestWith(['legacy', 'other', 'ownership_identity_conflict'])]
    public function test_dataset_ownership_requires_positive_matching_identities(?string $taskDataset, ?string $sourceDataset, string $reason): void
    {
        $models = $this->fixture();
        $models['task']->forceFill(['dataset_id' => $taskDataset])->save();
        $models['source']->forceFill(['dataset_id' => $sourceDataset])->save();
        $this->assertRefused($reason);
    }

    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith([' '])]
    public function test_source_workflow_must_be_present(?string $workflow): void
    {
        $models = $this->fixture();
        $models['source']->forceFill(['temporal_workflow_id' => $workflow])->save();
        $this->assertRefused('missing_ownership_identity');
    }

    #[TestWith([[], 'missing_source_run_identity'])]
    #[TestWith([['temporal' => []], 'missing_source_run_identity'])]
    #[TestWith([['temporal' => ['run_id' => null]], 'workflow_run_identity_conflict'])]
    #[TestWith([['temporal' => ['run_id' => 'other']], 'workflow_run_identity_conflict'])]
    #[TestWith([['temporal' => ['workflow_id' => 'other', 'run_id' => 'run']], 'workflow_run_identity_conflict'])]
    public function test_source_run_requires_authoritative_matching_evidence(array $identity, string $reason): void
    {
        $models = $this->fixture();
        $metadata = $models['source']->metadata;
        unset($metadata['temporal']);
        $models['source']->forceFill(['metadata' => [...$metadata, ...$identity]])->save();
        $this->assertRefused($reason);
    }

    public function test_persisted_worker_event_can_prove_the_source_run(): void
    {
        $models = $this->fixture();
        $metadata = $models['source']->metadata;
        unset($metadata['temporal']);
        $metadata['worker_event'] = ['workflow_id' => 'workflow', 'run_id' => 'run'];
        $models['source']->forceFill(['metadata' => $metadata])->save();
        $this->assertSame(1, $this->command(['--apply' => true, '--job-id' => 'job', '--evidence-ref' => 'audit:approved'])['reconciled']);
    }

    #[TestWith(['job', 'task_id'])]
    #[TestWith(['job', 'source_id'])]
    #[TestWith(['source', 'task_id'])]
    public function test_missing_parent_link_identity_cannot_use_approval(string $model, string $key): void
    {
        $models = $this->fixture();
        $models[$model]->forceFill([$key => ''])->save();
        $this->assertRefused($model === 'source' ? 'missing_ownership_identity' : 'missing_job_task_or_source');
    }

    #[TestWith(['dataset'])]
    #[TestWith(['run'])]
    public function test_source_identity_changes_invalidate_temporal_evidence(string $change): void
    {
        $models = $this->fixture();
        Http::swap(new Factory);
        Http::fake(function () use ($models, $change) {
            if ($change === 'dataset') {
                $models['source']->forceFill(['dataset_id' => null])->save();
            } else {
                $metadata = $models['source']->metadata;
                unset($metadata['temporal']);
                $models['source']->forceFill(['metadata' => $metadata])->save();
            }

            return Http::response(['workflow_id' => 'workflow', 'run_id' => 'run', 'status' => 'FAILED']);
        });
        $this->assertRefused('ownership_changed_during_temporal_inspection');
    }

    public function test_output_only_ownership_discovers_unresolved_replacement_after_latest_source_advances(): void
    {
        $models = $this->fixture();
        $this->outputOwner();
        $this->replacement();
        $snapshot = app(TerminalProjectionRepository::class)->snapshot($models['stage']->id);
        $this->assertSame(['adoc_output'], array_column($snapshot['documents'], 'document_id'));
        $this->assertSame(['op_output'], array_column($snapshot['operations'], 'operation_id'));
        $this->assertRefused('unresolved_replacement');
    }

    public function test_output_only_ownership_without_unresolved_work_can_reconcile(): void
    {
        $this->fixture();
        $output = $this->outputOwner();
        $before = $output->fresh()->getAttributes();
        $this->assertSame(1, $this->command(['--apply' => true, '--job-id' => 'job', '--evidence-ref' => 'audit:approved'])['reconciled']);
        $this->assertSame($before, $output->fresh()->getAttributes());
    }

    #[TestWith(['source_id'])]
    #[TestWith(['task_id'])]
    #[TestWith(['job_id'])]
    public function test_conflicting_output_execution_ownership_refuses(string $key): void
    {
        $this->fixture();
        $output = $this->outputOwner();
        $output->forceFill([$key => 'other'])->save();
        $this->assertRefused('conflicting_output_ownership');
    }

    public function test_latest_source_and_output_document_owners_cannot_disagree(): void
    {
        $this->fixture();
        $this->outputOwner();
        ManagedDocument::query()->create(['document_id' => 'adoc_other', 'dataset_id' => 'legacy', 'status' => 'deleted', 'latest_source_id' => 'source']);
        $this->assertRefused('conflicting_managed_ownership');
    }

    #[TestWith(['output'])]
    #[TestWith(['replacement'])]
    #[TestWith(['output_reassignment'])]
    public function test_durable_relationship_changes_invalidate_temporal_evidence(string $change): void
    {
        $this->fixture();
        if ($change !== 'output') {
            $output = $this->outputOwner();
        }
        Http::swap(new Factory);
        Http::fake(function () use ($change, &$output) {
            if ($change === 'output') {
                $this->outputOwner();
            } elseif ($change === 'replacement') {
                $this->replacement();
            } else {
                $output->forceFill(['job_id' => 'other'])->save();
            }

            return Http::response(['workflow_id' => 'workflow', 'run_id' => 'run', 'status' => 'FAILED']);
        });
        $this->assertRefused('ownership_changed_during_temporal_inspection');
    }

    public function test_multiple_output_owners_with_one_unresolved_association_refuse(): void
    {
        $this->fixture();
        $this->outputOwner();
        $this->outputOwner('adoc_unsafe');
        $this->replacement('adoc_unsafe');
        $this->assertRefused('unresolved_replacement');
    }

    #[TestWith(['dataset_id', 'other'])]
    #[TestWith(['dataset_id', ''])]
    #[TestWith(['workflow_input', ['dataset_id' => 'other']])]
    #[TestWith(['workflow_input', ['managed_document_id' => 'adoc_other']])]
    public function test_output_linked_operation_cannot_claim_a_different_owner(string $key, mixed $value): void
    {
        $this->fixture();
        $this->outputOwner();
        $this->replacement()->forceFill(['purpose' => 'delete', 'status' => 'completed', $key => $value])->save();
        $this->assertRefused('managed_operation_ownership_conflict');
    }

    public function test_output_owner_dataset_must_match_the_candidate(): void
    {
        $this->fixture();
        $this->outputOwner();
        ManagedDocument::query()->whereKey('adoc_output')->update(['dataset_id' => 'other']);
        $this->assertRefused('managed_owner_not_terminal');
    }

    #[TestWith(['delete', 'pending', 'unresolved_replacement'])]
    #[TestWith(['delete', 'failed', 'unresolved_replacement'])]
    #[TestWith(['delete', 'future', 'unresolved_replacement'])]
    #[TestWith(['update', 'continued', null])]
    #[TestWith(['update', 'abandoned', null])]
    #[TestWith(['delete', 'completed', null])]
    public function test_output_linked_operations_use_terminal_lifecycle_semantics(string $purpose, string $status, ?string $reason): void
    {
        $this->fixture();
        $this->outputOwner();
        $this->replacement()->forceFill(['purpose' => $purpose, 'status' => $status])->save();
        if ($reason !== null) {
            $this->assertRefused($reason);
        } else {
            $this->assertSame(1, $this->command(['--apply' => true, '--job-id' => 'job', '--evidence-ref' => 'audit:approved'])['reconciled']);
        }
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function refusedCommandCases(): iterable
    {
        foreach (['temporal_active', 'unreleased_writer', 'ownership_identity_conflict'] as $reason) {
            foreach ([false, true] as $apply) {
                foreach ([false, true] as $json) {
                    yield $reason.'/'.($apply ? 'apply' : 'dry-run').'/'.($json ? 'json' : 'human') => [$reason, $apply, $json];
                }
            }
        }
    }

    #[DataProvider('refusedCommandCases')]
    public function test_safety_refusals_exit_failure_in_both_modes_and_formats(string $reason, bool $apply, bool $json): void
    {
        $models = $this->fixture();
        if ($reason === 'temporal_active') {
            $this->fakeTemporal('RUNNING');
        } elseif ($reason === 'unreleased_writer') {
            $metadata = $models['source']->metadata;
            $metadata['managed_writers'] = ['writer' => ['status' => 'active']];
            $models['source']->forceFill(['metadata' => $metadata])->save();
        } else {
            $models['source']->forceFill(['dataset_id' => 'other'])->save();
        }
        $before = DB::table('pipeline_stage_states')->get();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $report = $this->command(['--job-id' => 'job', '--apply' => $apply, '--json' => $json, '--evidence-ref' => 'audit:approved'], 1);

        $this->assertSame('refused', $report['status']);
        $this->assertSame($reason, $report['candidates'][0]['reason']);
        $this->assertSame(! $apply, $report['dry_run']);
        $this->assertSame($apply ? 'refused' : 'dry_run', $report['candidates'][0]['action']);
        $this->assertSame(0, $report['reconciled']);
        $this->assertEquals($before, DB::table('pipeline_stage_states')->get());
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|drop|alter|create|truncate)\b/i', $sql);
        }
    }

    #[TestWith(['*'])]
    #[TestWith(['job%'])]
    #[TestWith(['job?'])]
    #[TestWith(['job[0]'])]
    #[TestWith([' job'])]
    #[TestWith([''])]
    public function test_malformed_scope_is_an_explicit_failure(string $jobId): void
    {
        $this->fixture();
        foreach ([false, true] as $apply) {
            foreach ([false, true] as $json) {
                $report = $this->command(['--apply' => $apply, '--json' => $json, '--job-id' => $jobId], 1);
                $this->assertSame('refused', $report['status']);
                $this->assertSame(0, $report['reconciled']);
            }
        }
        $this->assertSame(1, $this->drainCount());
        Http::assertNothingSent();
    }

    #[TestWith([false, false])]
    #[TestWith([false, true])]
    #[TestWith([true, false])]
    #[TestWith([true, true])]
    public function test_unknown_scope_differs_from_a_known_no_candidate_job(bool $apply, bool $json): void
    {
        $models = $this->fixture();
        $report = $this->command(['--apply' => $apply, '--json' => $json, '--job-id' => 'unknown-job'], 1);
        $this->assertSame('not_found', $report['status']);
        $this->assertSame('job_not_found', $report['error']);
        $models['stage']->forceFill(['status' => 'failed'])->save();
        $report = $this->command(['--apply' => $apply, '--json' => $json, '--job-id' => 'job']);
        $this->assertSame('no_candidate', $report['status']);
        Http::assertNothingSent();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_command_outcomes_distinguish_candidate_repair_and_idempotent_noop(bool $json): void
    {
        $this->fixture();
        $options = ['--job-id' => 'job', '--evidence-ref' => 'audit:approved', '--json' => $json];
        $this->assertSame('candidate', $this->command($options)['status']);
        $this->assertSame('reconciled', $this->command(['--apply' => true, ...$options])['status']);
        $this->assertSame('no_candidate', $this->command($options)['status']);
        $this->assertSame('no_candidate', $this->command(['--apply' => true, ...$options])['status']);
    }

    #[TestWith([503, ['detail' => 'Temporal inspection is unavailable.']])]
    #[TestWith([404, ['status' => 'NOT_FOUND', 'workflow_id' => 'workflow', 'run_id' => 'run']])]
    #[TestWith([200, 'malformed-json'])]
    #[TestWith([200, []])]
    public function test_unavailable_namespace_transport_or_malformed_response_cannot_use_approval(int $http, array|string $body): void
    {
        $this->fixture();
        Http::swap(new Factory);
        Http::fake(['*' => Http::response($body, $http)]);
        $this->assertRefused($http === 200 && $body === [] ? 'temporal_identity_conflict' : 'temporal_unavailable');
    }

    private function outputOwner(string $documentId = 'adoc_output'): ManagedDocumentOutput
    {
        ManagedDocument::query()->create(['document_id' => $documentId, 'dataset_id' => 'legacy', 'status' => 'failed', 'latest_source_id' => 'newer-source']);

        return ManagedDocumentOutput::query()->create(['document_id' => $documentId, 'source_id' => 'source',
            'task_id' => 'task', 'job_id' => 'job', 'bridge_document_id' => 'bridge_'.$documentId,
            'qdrant_collection' => 'historical', 'active' => true, 'status' => 'indexed']);
    }

    private function replacement(string $documentId = 'adoc_output'): ManagedDocumentDeletion
    {
        return ManagedDocumentDeletion::query()->create(['operation_id' => 'op_output', 'document_id' => $documentId, 'dataset_id' => 'legacy',
            'purpose' => 'update', 'status' => 'completed', 'workflow_id' => 'delete-workflow', 'workflow_input' => []]);
    }

    private function fixture(string $suffix = ''): array
    {
        $task = PipelineTask::query()->create(['task_id' => 'task'.$suffix, 'dataset_id' => 'legacy', 'status' => 'failed']);
        $source = IngestionSource::query()->create(['source_id' => 'source'.$suffix, 'source_url' => 'upload://audit.pdf',
            'task_id' => $task->task_id, 'dataset_id' => 'legacy', 'index_status' => 'deleted', 'temporal_workflow_id' => 'workflow'.$suffix,
            'metadata' => ['managed_writer_protocol' => 1, 'managed_target_manifest_complete' => true,
                'managed_writer_gate' => 'closed', 'managed_writers' => [], 'temporal' => ['run_id' => 'run'.$suffix]]]);
        $job = PipelineJob::query()->create(['job_id' => 'job'.$suffix, 'task_id' => $task->task_id, 'source_id' => $source->source_id,
            'status' => 'failed', 'index_status' => 'cancelled', 'current_stage' => 'temporal.cancel_requested',
            'temporal_workflow_id' => 'workflow'.$suffix, 'temporal_run_id' => 'run'.$suffix]);
        $stage = PipelineStageState::query()->create(['pipeline_job_id' => $job->id, 'job_id' => $job->job_id, 'stage' => 'ingest', 'status' => 'running']);
        $this->fakeTemporal('NOT_FOUND');

        return compact('task', 'source', 'job', 'stage');
    }

    private function fakeTemporal(string $status, int $http = 200): void
    {
        Http::swap(new Factory);
        Http::fake(fn ($request) => Http::response(['workflow_id' => $request['workflow_id'], 'run_id' => $request['run_id'], 'status' => $status], $http));
    }

    private function command(array $options, int $expectedExit = 0): array
    {
        $exit = Artisan::call('pipeline:reconcile-terminal-projections', ['--json' => true, ...$options]);
        $output = Artisan::output();
        $this->assertSame($expectedExit, $exit, $output);

        if (($options['--json'] ?? true) === false) {
            $lines = explode("\n", trim($output));
            $report = json_decode(array_pop($lines), true, flags: JSON_THROW_ON_ERROR);
            if ($lines !== []) {
                $report['candidates'] = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
            }

            return $report;
        }

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertRefused(string $reason): void
    {
        $before = DB::table('pipeline_stage_states')->get();
        $report = $this->command(['--apply' => true, '--job-id' => 'job', '--evidence-ref' => 'audit:approved'], 1);
        $this->assertSame($reason, $report['candidates'][0]['reason']);
        $this->assertSame(0, $report['reconciled']);
        $this->assertEquals($before, DB::table('pipeline_stage_states')->get());
    }

    private function drainCount(): int
    {
        return app(LegacyDrainStatusService::class)->report()['counts']['nonterminal_stage_states'];
    }
}
