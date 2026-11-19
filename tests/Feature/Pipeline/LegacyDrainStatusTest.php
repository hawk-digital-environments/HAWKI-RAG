<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Services\Pipeline\LegacyDrainStatusService;
use App\Services\Pipeline\Repositories\PipelineOwnershipRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LegacyDrainStatusTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['pipeline_tasks', 'future-state', 1])]
    #[TestWith(['pipeline_tasks', null, 1])]
    #[TestWith(['pipeline_tasks', 'cancelled', 1])]
    #[TestWith(['pipeline_tasks', 'skipped', 1])]
    #[TestWith(['pipeline_tasks', 'completed', 0])]
    #[TestWith(['pipeline_tasks', 'failed', 0])]
    #[TestWith(['pipeline_jobs', 'future-state', 1])]
    #[TestWith(['pipeline_jobs', null, 1])]
    #[TestWith(['pipeline_jobs', 'cancelled', 1])]
    #[TestWith(['pipeline_jobs', 'skipped', 0])]
    #[TestWith(['pipeline_stage_states', 'future-state', 1])]
    #[TestWith(['pipeline_stage_states', null, 1])]
    #[TestWith(['pipeline_stage_states', 'cancelled', 1])]
    #[TestWith(['pipeline_stage_states', 'skipped', 0])]
    public function test_only_known_terminal_statuses_are_excluded(string $table, ?string $status, int $expected): void
    {
        // The real schema rejects NULL today; simulate an older/partial schema
        // only inside the guarded :memory: test database.
        if ($status === null) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->string('status', 64)->nullable()->change());
        }
        $row = ['status' => $status];
        if ($table === 'pipeline_tasks') {
            $row['task_id'] = 'task-status';
        } elseif ($table === 'pipeline_jobs') {
            $row['job_id'] = 'job-status';
            $row['index_status'] = 'ready';
        } else {
            $job = DB::table('pipeline_jobs')->insertGetId(['job_id' => 'stage-job', 'status' => 'completed', 'index_status' => 'ready']);
            $row += ['pipeline_job_id' => $job, 'job_id' => 'stage-job', 'stage' => 'ingest'];
        }
        DB::table($table)->insert($row);
        $key = match ($table) {
            'pipeline_tasks' => 'nonterminal_pipeline_tasks',
            'pipeline_jobs' => 'nonterminal_pipeline_jobs',
            default => 'nonterminal_stage_states',
        };
        $report = $this->report();
        $this->assertSame($expected, $report['counts'][$key]);
        $this->assertSame($expected === 0, $report['legacy_work_drained']);
        $this->assertHealthAgrees($report);
    }

    #[TestWith(['completed', 'ready', 0])]
    #[TestWith(['failed', 'cancelled', 0])]
    #[TestWith(['failed', 'failed', 0])]
    #[TestWith(['skipped', 'deleted', 0])]
    #[TestWith(['completed', 'running', 1])]
    #[TestWith(['failed', 'pending', 1])]
    #[TestWith(['failed', 'unknown', 1])]
    #[TestWith(['completed', null, 1])]
    public function test_terminal_job_cannot_hide_nonterminal_or_unknown_index_status(string $status, ?string $index, int $expected): void
    {
        DB::table('pipeline_jobs')->insert(['job_id' => 'index-audit', 'status' => $status, 'index_status' => $index]);
        $report = $this->report();
        $this->assertSame(0, $report['counts']['nonterminal_pipeline_jobs']);
        $this->assertSame($expected, $report['counts']['nonterminal_job_index_states']);
        $this->assertSame($expected === 0, $report['legacy_work_drained']);
        $this->assertHealthAgrees($report);
    }

    public function test_missing_job_index_column_fails_closed(): void
    {
        Schema::table('pipeline_jobs', function (Blueprint $table): void {
            $table->dropIndex(['index_status']);
            $table->dropColumn('index_status');
        });
        $report = $this->report();
        $this->assertNull($report['counts']['nonterminal_job_index_states']);
        $this->assertFalse($report['legacy_work_drained']);
        $this->assertHealthAgrees($report);
    }

    public function test_active_outputs_block_drain_even_without_any_tasks_or_jobs(): void
    {
        $this->document();
        DB::table('managed_document_outputs')->insert(['document_id' => 'adoc_drain', 'bridge_document_id' => 'owned',
            'qdrant_collection' => 'owned-collection', 'active' => true]);
        $report = $this->report();
        $this->assertSame(0, $report['counts']['nonterminal_pipeline_tasks']);
        $this->assertSame(0, $report['counts']['nonterminal_pipeline_jobs']);
        $this->assertSame(1, $report['counts']['active_managed_outputs']);
        $this->assertFalse($report['legacy_work_drained']);
        $this->assertHealthAgrees($report);
    }

    #[TestWith([['writer' => ['status' => 'active']], 1, 0])]
    #[TestWith([['writer' => ['status' => 'released']], 0, 0])]
    #[TestWith([['writer' => ['status' => 'unknown']], 1, 0])]
    #[TestWith([['writer' => ['status' => null]], 1, 0])]
    #[TestWith([['writer' => []], 1, 0])]
    #[TestWith([['writer' => null], 1, 0])]
    #[TestWith([[], 0, 0])]
    #[TestWith([null, 0, 1])]
    #[TestWith(['invalid', 0, 1])]
    #[TestWith([[['status' => 'released']], 0, 1])]
    public function test_writer_registry_requires_positive_release_evidence(mixed $writers, int $unreleased, int $unknown): void
    {
        $this->source(['managed_writer_protocol' => 1, 'managed_target_manifest_complete' => true, 'managed_writers' => $writers]);
        $report = $this->report();
        $this->assertSame($unreleased, $report['counts']['unreleased_managed_writers']);
        $this->assertSame($unknown, $report['counts']['unknown_managed_writer_sources']);
        $this->assertSame($unreleased === 0 && $unknown === 0, $report['legacy_work_drained']);
        $this->assertHealthAgrees($report);
    }

    #[TestWith([null])]
    #[TestWith(['[]'])]
    #[TestWith(['not-json'])]
    #[TestWith(['{}'])]
    public function test_missing_or_unproven_writer_metadata_is_not_an_empty_registry(?string $metadata): void
    {
        DB::table('ingestion_sources')->insert(['source_id' => 'unproven', 'source_url' => 'upload://old.pdf',
            'index_status' => 'ready', 'metadata' => $metadata]);
        $report = $this->report();
        $this->assertSame(1, $report['counts']['unknown_managed_writer_sources']);
        $this->assertFalse($report['legacy_work_drained']);
        $this->assertHealthAgrees($report);
    }

    public function test_never_dispatched_reservation_has_no_external_writer(): void
    {
        $this->source(['managed_dispatch' => ['attempted' => false]]);
        $this->assertTrue($this->report()['legacy_work_drained']);
    }

    public function test_deleting_source_and_failed_deletion_remain_unresolved(): void
    {
        $this->source(['managed_writer_protocol' => 1, 'managed_target_manifest_complete' => true], 'deleting');
        $this->deletion('delete', 'failed');
        $report = $this->report();
        $this->assertSame(1, $report['counts']['deleting_ingestion_sources']);
        $this->assertSame(1, $report['counts']['active_ingestion_sources']);
        $this->assertSame(1, $report['counts']['open_managed_document_deletions']);
        $this->assertFalse($report['legacy_work_drained']);
        $this->assertHealthAgrees($report);
    }

    #[TestWith([null, 1])]
    #[TestWith([['state' => 'reserved'], 1])]
    #[TestWith([['state' => 'dispatching'], 1])]
    #[TestWith([['state' => 'uncertain'], 1])]
    #[TestWith([['state' => 'future-state'], 1])]
    #[TestWith([['state' => null], 1])]
    #[TestWith([['state' => 'confirmed'], 0])]
    public function test_terminal_job_status_cannot_hide_pending_recovery(mixed $pending, int $expected): void
    {
        DB::table('pipeline_jobs')->insert(['job_id' => 'recovery', 'status' => 'failed', 'index_status' => 'cancelled',
            'metadata' => json_encode(['pending_recovery' => $pending], JSON_THROW_ON_ERROR)]);
        $report = $this->report();
        $this->assertSame($expected, $report['counts']['unresolved_recovery_jobs']);
        $this->assertSame($expected === 0, $report['legacy_work_drained']);
        $this->assertHealthAgrees($report);
    }

    #[TestWith(['completed', 1])]
    #[TestWith(['continued', 0])]
    #[TestWith(['abandoned', 0])]
    public function test_completed_cleanup_does_not_hide_an_unpublished_replacement(string $status, int $expected): void
    {
        $this->deletion('update', $status);
        $report = $this->report();
        $this->assertSame(0, $report['counts']['open_managed_document_deletions']);
        $this->assertSame($expected, $report['counts']['unresolved_managed_replacements']);
        $this->assertSame($expected === 0, $report['legacy_work_drained']);
        $this->assertHealthAgrees($report);
    }

    #[TestWith(['pipeline_jobs', 'unresolved_recovery_jobs'])]
    #[TestWith(['ingestion_sources', 'unreleased_managed_writers'])]
    #[TestWith(['managed_document_deletions', 'unresolved_managed_replacements'])]
    #[TestWith(['rag_graph_failures', 'rag_graph_failures'])]
    public function test_missing_tables_are_unknown_for_both_command_and_health(string $table, string $key): void
    {
        Schema::drop($table);
        $this->assertSame(1, Artisan::call('rag:legacy-drain-status', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNull($report['counts'][$key]);
        $this->assertFalse($report['schema_complete']);
        $this->assertFalse($report['legacy_work_drained']);
        $this->assertHealthAgrees($report);
    }

    public function test_missing_metadata_column_is_unknown_not_zero(): void
    {
        Schema::table('ingestion_sources', fn (Blueprint $table) => $table->dropColumn('metadata'));
        $report = $this->report();
        $this->assertNull($report['counts']['unreleased_managed_writers']);
        $this->assertFalse($report['legacy_work_drained']);
        $this->assertHealthAgrees($report);
    }

    public function test_malformed_recovery_metadata_cannot_hide_an_uncertain_reservation(): void
    {
        DB::table('pipeline_jobs')->insert(['job_id' => 'malformed', 'status' => 'failed', 'metadata' => 'not-json']);
        $report = $this->report();
        $this->assertSame(1, $report['counts']['unresolved_recovery_jobs']);
        $this->assertFalse($report['legacy_work_drained']);
        $this->assertHealthAgrees($report);
    }

    public function test_unprocessed_terminal_worker_event_is_still_pending_work(): void
    {
        $job = DB::table('pipeline_jobs')->insertGetId(['job_id' => 'event-job', 'status' => 'completed', 'index_status' => 'ready']);
        DB::table('pipeline_worker_events')->insert(['pipeline_job_id' => $job, 'event_id' => 'pending', 'job_id' => 'event-job',
            'source_id' => 'source', 'workflow_id' => 'workflow', 'run_id' => 'run', 'activity_id' => 'activity',
            'attempt' => 1, 'event_type' => 'index', 'producer' => 'indexer', 'stage' => 'ingest', 'phase' => 'finished',
            'status' => 'completed', 'payload_hash' => str_repeat('a', 64), 'payload' => '{}', 'occurred_at' => '2026-11-19 00:00:00']);
        $report = $this->report();
        $this->assertSame(1, $report['counts']['unprocessed_worker_events']);
        $this->assertFalse($report['legacy_work_drained']);
        $this->assertHealthAgrees($report);
    }

    public function test_report_is_read_only_and_never_waives_operational_gates(): void
    {
        $this->source(['managed_writer_protocol' => 1, 'managed_target_manifest_complete' => true, 'managed_writers' => []]);
        $before = DB::table('ingestion_sources')->first();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        Http::preventStrayRequests();
        $this->assertSame(0, Artisan::call('rag:legacy-drain-status', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($report['legacy_work_drained']);
        $this->assertFalse($report['safe_to_drop']);
        foreach (['temporal_workflows_schedules_reconciled', 'callback_quiet_window', 'no_new_legacy_writes',
            'history_retention', 'backup_restore_verification', 'python_production_acceptance'] as $gate) {
            $this->assertSame('unresolved', $report['gates'][$gate]);
        }
        $this->assertFalse($report['gates']['rollback_window_closed']);
        $this->assertFalse($report['gates']['epoch15b_drop_approval']);
        $this->assertEquals($before, DB::table('ingestion_sources')->first());
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|drop|alter|create|truncate)\b/i', $sql);
        }
        Http::assertNothingSent();
    }

    private function report(): array
    {
        $report = app(LegacyDrainStatusService::class)->report();
        $this->assertFalse($report['safe_to_drop']);

        return $report;
    }

    private function assertHealthAgrees(array $report): void
    {
        $this->assertSame($report['legacy_work_drained'], app(PipelineOwnershipRepository::class)->drainCounts()['legacy_drain_complete']);
    }

    private function source(array $metadata, string $status = 'ready'): void
    {
        DB::table('ingestion_sources')->insert(['source_id' => 'source-drain', 'source_url' => 'upload://old.pdf',
            'index_status' => $status, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
    }

    private function document(): void
    {
        DB::table('managed_documents')->insert(['document_id' => 'adoc_drain', 'dataset_id' => 'legacy']);
    }

    private function deletion(string $purpose, string $status): void
    {
        $this->document();
        DB::table('managed_document_deletions')->insert(['operation_id' => 'delete_'.str_repeat('a', 64),
            'document_id' => 'adoc_drain', 'dataset_id' => 'legacy', 'purpose' => $purpose,
            'status' => $status, 'workflow_id' => 'legacy-workflow', 'workflow_input' => '{}']);
    }
}
