<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PostgresMigrationUpgradeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(getenv('RUN_POSTGRES_MIGRATION_TESTS') ?: false, FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set RUN_POSTGRES_MIGRATION_TESTS=1 to run isolated PostgreSQL migration tests.');
        }

        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('The pdo_pgsql extension is required for PostgreSQL migration tests.');
        }
    }

    public function test_pipeline_reconciliation_repairs_a_legacy_schema_idempotently(): void
    {
        $this->withIsolatedPostgresSchema(function (): void {
            Schema::create('pipeline_jobs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('job_id', 191)->unique();
                $table->string('status', 64)->default('pending');
                $table->text('source_url')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });

            Schema::create('pipeline_tasks', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('task_id', 191)->unique();
                $table->string('dataset_id', 191)->nullable();
                $table->string('profile_id', 191)->nullable();
                $table->string('status', 64)->default('pending');
                $table->timestamps();
                $table->index(['status', 'profile_id']);
            });

            Schema::create('pipeline_stage_states', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('job_id', 191);
                $table->string('stage', 64);
                $table->unique(['job_id', 'stage']);
                $table->index('job_id');
            });

            Schema::create('ingestion_sources', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('dataset_id', 191)->nullable()->index();
                $table->string('index_status', 64)->default('pending');
                $table->index(['dataset_id', 'index_status']);
            });

            DB::table('pipeline_jobs')->insert([
                ['job_id' => 'legacy-pending', 'status' => 'pending'],
                ['job_id' => 'legacy-partial', 'status' => 'partial'],
            ]);
            DB::table('pipeline_tasks')->insert([
                'task_id' => 'legacy-task',
                'profile_id' => 'removed-profile',
                'status' => 'paused',
            ]);

            $this->runMigration('2026_07_24_000000_reconcile_pipeline_schema_for_upgrades.php');
            $this->runMigration('2026_07_24_000000_reconcile_pipeline_schema_for_upgrades.php');

            foreach ([
                'task_id',
                'source_id',
                'parent_job_id',
                'job_type',
                'local_path',
                'content_hash',
                'error_message',
                'finished_at',
                'temporal_workflow_id',
                'temporal_run_id',
                'temporal_schedule_id',
                'index_status',
            ] as $column) {
                $this->assertTrue(Schema::hasColumn('pipeline_jobs', $column), "Missing reconciled column [{$column}].");
            }

            $this->assertFalse(Schema::hasColumn('pipeline_tasks', 'profile_id'));
            $this->assertTrue(Schema::hasIndex('pipeline_tasks', ['status']));
            $this->assertFalse(Schema::hasIndex('pipeline_stage_states', ['job_id']));
            $this->assertTrue(Schema::hasIndex('pipeline_stage_states', ['job_id', 'stage'], 'unique'));
            $this->assertFalse(Schema::hasIndex('ingestion_sources', ['dataset_id']));
            $this->assertTrue(Schema::hasIndex('ingestion_sources', ['dataset_id', 'index_status']));
            $this->assertSame('queued', DB::table('pipeline_jobs')->where('job_id', 'legacy-pending')->value('status'));
            $this->assertSame('failed', DB::table('pipeline_jobs')->where('job_id', 'legacy-partial')->value('status'));
            $this->assertSame('failed', DB::table('pipeline_tasks')->where('task_id', 'legacy-task')->value('status'));
        });
    }

    public function test_restored_storage_bridge_preserves_existing_outputs_and_normalizes_metadata(): void
    {
        $this->withIsolatedPostgresSchema(function (): void {
            $deletedAt = '2026-07-23 12:00:00';
            $existingOutputId = 9000;

            $this->runMigration('2026_07_08_150000_create_managed_document_tables.php');
            $this->runMigration('2026_07_08_150000_create_managed_document_tables.php');
            $this->createLegacyAssistantTables();
            $this->createLegacyMetadataTables();

            $this->insertManagedConflictState($existingOutputId, $deletedAt);
            $this->insertLegacyManagedDocumentData();
            $this->insertConflictingLegacyManagedDocumentData();
            $this->insertLegacyMetadata();

            $this->runMigration('2026_07_13_000000_migrate_assistant_document_storage_to_managed_documents.php');

            $this->assertFalse(Schema::hasTable('assistant_document_outputs'));
            $this->assertFalse(Schema::hasTable('assistant_documents'));
            $this->assertDatabaseHas('managed_documents', [
                'document_id' => 'adoc_legacy',
                'dataset_id' => 'legacy-dataset',
            ]);
            $this->assertDatabaseHas('managed_document_outputs', [
                'id' => $existingOutputId,
                'document_id' => 'adoc_existing',
                'bridge_document_id' => 'doc-existing',
                'qdrant_collection' => 'hawki_existing',
                'status' => 'deleted',
                'active' => false,
                'deleted_at' => $deletedAt,
            ]);
            $this->assertDatabaseHas('managed_documents', [
                'document_id' => 'adoc_existing',
                'dataset_id' => 'managed-dataset',
                'display_name' => 'managed.pdf',
                'status' => 'deleted',
                'deleted_at' => $deletedAt,
            ]);
            $this->assertDatabaseHas('managed_document_outputs', [
                'document_id' => 'adoc_legacy',
                'bridge_document_id' => 'doc-legacy',
                'qdrant_collection' => 'hawki_legacy',
            ]);

            $legacyOutputId = (int) DB::table('managed_document_outputs')
                ->where('document_id', 'adoc_legacy')
                ->value('id');
            $this->assertNotSame($existingOutputId, $legacyOutputId);

            $nextOutputId = DB::table('managed_document_outputs')->insertGetId([
                'document_id' => 'adoc_legacy',
                'bridge_document_id' => 'doc-after-upgrade',
                'qdrant_collection' => 'hawki_legacy',
            ]);
            $this->assertGreaterThan(max($existingOutputId, $legacyOutputId), $nextOutputId);

            $this->assertCanonicalWorkflowMetadata('pipeline_tasks', 'task_id', 'legacy-task');
            $this->assertCanonicalWorkflowMetadata('pipeline_jobs', 'job_id', 'legacy-job');
            $this->assertCanonicalWorkflowMetadata('ingestion_sources', 'source_id', 'legacy-source');

            $documentMetadata = $this->jsonValue(
                DB::table('documents')->where('id', 'legacy-document')->value('metadata_json'),
            );
            $this->assertSame('adoc_legacy', $documentMetadata['managed_document_id']);
            $this->assertArrayNotHasKey('assistant_document_id', $documentMetadata);
        });
    }

    public function test_finalizer_repairs_a_half_applied_legacy_storage_upgrade_idempotently(): void
    {
        $this->withIsolatedPostgresSchema(function (): void {
            $deletedAt = '2026-07-22 08:30:00';
            $existingOutputId = 8000;

            $this->runMigration('2026_07_08_150000_create_managed_document_tables.php');
            $this->createLegacyAssistantTables();
            $this->insertManagedConflictState($existingOutputId, $deletedAt);
            $this->insertLegacyManagedDocumentData();
            $this->insertConflictingLegacyManagedDocumentData();

            $this->runMigration('2026_07_24_010000_finalize_managed_document_storage_upgrade.php');
            $this->runMigration('2026_07_24_010000_finalize_managed_document_storage_upgrade.php');

            $this->assertTrue(Schema::hasTable('managed_documents'));
            $this->assertTrue(Schema::hasTable('managed_document_outputs'));
            $this->assertFalse(Schema::hasTable('assistant_documents'));
            $this->assertFalse(Schema::hasTable('assistant_document_outputs'));
            $this->assertDatabaseHas('managed_documents', [
                'document_id' => 'adoc_legacy',
                'dataset_id' => 'legacy-dataset',
            ]);
            $this->assertDatabaseHas('managed_document_outputs', [
                'document_id' => 'adoc_legacy',
                'bridge_document_id' => 'doc-legacy',
            ]);
            $this->assertDatabaseHas('managed_documents', [
                'document_id' => 'adoc_existing',
                'dataset_id' => 'managed-dataset',
                'display_name' => 'managed.pdf',
                'status' => 'deleted',
                'deleted_at' => $deletedAt,
            ]);
            $this->assertDatabaseHas('managed_document_outputs', [
                'id' => $existingOutputId,
                'document_id' => 'adoc_existing',
                'bridge_document_id' => 'doc-existing',
                'qdrant_collection' => 'hawki_existing',
                'status' => 'deleted',
                'active' => false,
                'deleted_at' => $deletedAt,
            ]);

            $nextOutputId = DB::table('managed_document_outputs')->insertGetId([
                'document_id' => 'adoc_legacy',
                'bridge_document_id' => 'doc-after-finalizer',
                'qdrant_collection' => 'hawki_legacy',
            ]);
            $this->assertGreaterThan($existingOutputId, $nextOutputId);
        });
    }

    public function test_finalizer_retains_legacy_tables_when_an_insert_is_ignored_for_an_unrelated_conflict(): void
    {
        $this->withIsolatedPostgresSchema(function (): void {
            $this->runMigration('2026_07_08_150000_create_managed_document_tables.php');
            $this->createLegacyAssistantTables();

            Schema::table('managed_document_outputs', function (Blueprint $table): void {
                $table->unique('qdrant_collection', 'managed_outputs_qdrant_unique');
            });

            DB::table('managed_documents')->insert([
                'document_id' => 'adoc_blocker',
                'dataset_id' => 'managed-dataset',
                'source_type' => 'upload',
                'status' => 'indexed',
            ]);
            DB::table('managed_document_outputs')->insert([
                'document_id' => 'adoc_blocker',
                'bridge_document_id' => 'doc-blocker',
                'qdrant_collection' => 'hawki_legacy',
            ]);
            $this->insertLegacyManagedDocumentData();

            try {
                $this->runMigration('2026_07_24_010000_finalize_managed_document_storage_upgrade.php');
                self::fail('The finalizer dropped legacy tables despite a missing managed output.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    'output finalization failed verification',
                    $exception->getMessage(),
                );
            }

            $this->assertTrue(Schema::hasTable('assistant_documents'));
            $this->assertTrue(Schema::hasTable('assistant_document_outputs'));
        });
    }

    public function test_finalizer_does_not_rewind_an_output_sequence_after_high_ids_are_deleted(): void
    {
        $this->withIsolatedPostgresSchema(function (): void {
            $this->runMigration('2026_07_08_150000_create_managed_document_tables.php');
            $this->createLegacyAssistantTables();

            DB::table('managed_documents')->insert([
                'document_id' => 'adoc_sequence',
                'dataset_id' => 'managed-dataset',
                'source_type' => 'upload',
                'status' => 'indexed',
            ]);
            DB::table('managed_document_outputs')->insert([
                'id' => 10,
                'document_id' => 'adoc_sequence',
                'bridge_document_id' => 'doc-low',
                'qdrant_collection' => 'hawki_sequence',
            ]);

            DB::statement(
                "SELECT setval(pg_get_serial_sequence('managed_document_outputs', 'id'), 11999, true)",
            );
            $deletedOutputId = (int) DB::table('managed_document_outputs')->insertGetId([
                'document_id' => 'adoc_sequence',
                'bridge_document_id' => 'doc-deleted-high',
                'qdrant_collection' => 'hawki_sequence',
            ]);
            $this->assertSame(12000, $deletedOutputId);
            DB::table('managed_document_outputs')->where('id', $deletedOutputId)->delete();

            $this->runMigration('2026_07_24_010000_finalize_managed_document_storage_upgrade.php');

            $nextOutputId = (int) DB::table('managed_document_outputs')->insertGetId([
                'document_id' => 'adoc_sequence',
                'bridge_document_id' => 'doc-after-finalizer',
                'qdrant_collection' => 'hawki_sequence',
            ]);
            $this->assertGreaterThan($deletedOutputId, $nextOutputId);
        });
    }

    public function test_storage_migrations_block_concurrent_metadata_writes_until_the_upgrade_transaction_finishes(): void
    {
        $this->withIsolatedPostgresSchema(function (): void {
            $this->runMigration('2026_07_08_150000_create_managed_document_tables.php');
            $this->createLegacyMetadataTables();

            $canonicalMetadata = json_encode([
                'managed_document_id' => 'adoc_lock_probe',
            ], JSON_THROW_ON_ERROR);
            DB::table('pipeline_tasks')->insert([
                'task_id' => 'lock-probe-task',
                'metadata' => $canonicalMetadata,
            ]);

            $writerConnection = 'migration_upgrade_pgsql_writer';
            $migrationConnection = (string) config('database.default');
            $connectionConfig = config("database.connections.{$migrationConnection}");
            $this->assertIsArray($connectionConfig);
            config()->set("database.connections.{$writerConnection}", $connectionConfig);
            DB::purge($writerConnection);

            try {
                $writer = DB::connection($writerConnection);
                $writer->statement("SET lock_timeout TO '250ms'");

                foreach ([
                    '2026_07_13_000000_migrate_assistant_document_storage_to_managed_documents.php',
                    '2026_07_24_010000_finalize_managed_document_storage_upgrade.php',
                ] as $migration) {
                    DB::beginTransaction();

                    try {
                        $this->runMigration($migration);

                        try {
                            $writer->table('pipeline_tasks')
                                ->where('task_id', 'lock-probe-task')
                                ->update([
                                    'metadata' => json_encode([
                                        'managed_document_id' => 'adoc_concurrent_writer',
                                    ], JSON_THROW_ON_ERROR),
                                ]);
                            self::fail(
                                "A concurrent metadata writer was not blocked by [{$migration}].",
                            );
                        } catch (QueryException $exception) {
                            $this->assertSame('55P03', $exception->getCode());
                        }
                    } finally {
                        if (DB::transactionLevel() > 0) {
                            DB::rollBack();
                        }
                    }
                }

                $updated = $writer->table('pipeline_tasks')
                    ->where('task_id', 'lock-probe-task')
                    ->update([
                        'metadata' => json_encode([
                            'managed_document_id' => 'adoc_after_upgrade',
                        ], JSON_THROW_ON_ERROR),
                    ]);
                $this->assertSame(1, $updated);
            } finally {
                DB::purge($writerConnection);
                config()->offsetUnset("database.connections.{$writerConnection}");
            }
        });
    }

    public function test_rag_monitor_artifact_migration_uses_jsonb_and_cascades_failure_rows(): void
    {
        $this->withIsolatedPostgresSchema(function (): void {
            $this->runMigration('2026_05_29_010000_create_pipeline_state_tables.php');
            $this->runMigration('2026_08_03_000000_create_pipeline_worker_events_table.php');
            $this->runMigration('2026_08_05_000000_create_rag_monitor_artifact_tables.php');

            $this->assertTrue(Schema::hasTable('rag_ingestion_artifacts'));
            $this->assertTrue(Schema::hasTable('rag_graph_failures'));

            foreach ([
                ['rag_ingestion_artifacts', 'summary'],
                ['rag_ingestion_artifacts', 'graph_preview'],
                ['rag_graph_failures', 'context'],
            ] as [$table, $column]) {
                $type = DB::scalar(
                    'SELECT data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                    [$table, $column],
                );
                $this->assertSame('jsonb', $type, "Expected {$table}.{$column} to use JSONB.");
            }

            $jobId = DB::table('pipeline_jobs')->insertGetId([
                'job_id' => 'monitor-migration-job',
                'status' => 'running',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $eventId = DB::table('pipeline_worker_events')->insertGetId([
                'pipeline_job_id' => $jobId,
                'event_id' => 'evt_monitor_migration',
                'job_id' => 'monitor-migration-job',
                'task_id' => 'monitor-migration-task',
                'source_id' => 'monitor-migration-source',
                'workflow_id' => 'monitor-migration-workflow',
                'run_id' => 'monitor-migration-run',
                'activity_id' => 'mark_source_ready',
                'attempt' => 1,
                'event_type' => 'pipeline.stage.status',
                'producer' => 'indexer',
                'stage' => 'ingest',
                'phase' => 'mark_source_ready',
                'status' => 'completed',
                'payload_hash' => str_repeat('a', 64),
                'payload' => '{}',
                'occurred_at' => now(),
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $artifactId = DB::table('rag_ingestion_artifacts')->insertGetId([
                'pipeline_job_id' => $jobId,
                'pipeline_worker_event_id' => $eventId,
                'job_id' => 'monitor-migration-job',
                'task_id' => 'monitor-migration-task',
                'source_id' => 'monitor-migration-source',
                'dataset_id' => 'monitor-migration-dataset',
                'workflow_id' => 'monitor-migration-workflow',
                'run_id' => 'monitor-migration-run',
                'summary' => json_encode(['documents' => ['processed_docs' => 1]], JSON_THROW_ON_ERROR),
                'graph_preview' => json_encode(['total_triplets' => 2], JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('rag_graph_failures')->insert([
                'rag_ingestion_artifact_id' => $artifactId,
                'job_id' => 'monitor-migration-job',
                'source_id' => 'monitor-migration-source',
                'dataset_id' => 'monitor-migration-dataset',
                'document_id' => 'monitor-migration-document',
                'error_code' => 'graph_extraction_failed',
                'message' => 'Synthetic migration failure.',
                'context' => json_encode(['chunks' => 1], JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertSame(
                '1',
                DB::scalar("SELECT summary->'documents'->>'processed_docs' FROM rag_ingestion_artifacts WHERE id = ?", [$artifactId]),
            );
            $this->assertDatabaseCount('rag_graph_failures', 1);

            DB::table('rag_ingestion_artifacts')->where('id', $artifactId)->delete();
            $this->assertDatabaseCount('rag_graph_failures', 0);
        });
    }

    private function createLegacyAssistantTables(): void
    {
        Schema::create('assistant_documents', function (Blueprint $table): void {
            $table->string('assistant_document_id', 191)->primary();
            $table->string('dataset_id', 191);
            $table->string('display_name')->nullable();
            $table->string('source_type', 64)->default('upload');
            $table->text('source_url')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->char('source_checksum_sha256', 64)->nullable();
            $table->boolean('graph_enabled')->default(false);
            $table->string('status', 64)->default('accepted');
            $table->text('last_error')->nullable();
            $table->string('latest_source_id', 191)->nullable();
            $table->string('latest_task_id', 191)->nullable();
            $table->string('latest_job_id', 191)->nullable();
            $table->string('latest_document_version', 191)->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });

        Schema::create('assistant_document_outputs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('assistant_document_id', 191);
            $table->string('bridge_document_id', 191);
            $table->string('qdrant_collection', 191);
            $table->string('neo4j_namespace', 191)->nullable();
            $table->string('source_id', 191)->nullable();
            $table->string('task_id', 191)->nullable();
            $table->string('job_id', 191)->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->string('status', 64)->default('indexed');
            $table->boolean('active')->default(true);
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('assistant_document_id')
                ->references('assistant_document_id')
                ->on('assistant_documents')
                ->cascadeOnDelete();
            $table->unique(['assistant_document_id', 'bridge_document_id']);
        });
    }

    private function createLegacyMetadataTables(): void
    {
        foreach ([
            'pipeline_tasks' => 'task_id',
            'pipeline_jobs' => 'job_id',
            'ingestion_sources' => 'source_id',
        ] as $tableName => $identifier) {
            Schema::create($tableName, function (Blueprint $table) use ($identifier): void {
                $table->bigIncrements('id');
                $table->string($identifier, 191)->unique();
                $table->json('metadata')->nullable();
            });
        }

        Schema::create('documents', function (Blueprint $table): void {
            $table->string('id', 191)->primary();
            $table->json('metadata_json')->nullable();
        });
    }

    private function insertLegacyManagedDocumentData(): void
    {
        DB::table('assistant_documents')->insert([
            'assistant_document_id' => 'adoc_legacy',
            'dataset_id' => 'legacy-dataset',
            'display_name' => 'legacy.pdf',
            'source_type' => 'upload',
            'graph_enabled' => true,
            'status' => 'indexed',
            'latest_source_id' => 'legacy-source',
            'latest_task_id' => 'legacy-task',
            'latest_job_id' => 'legacy-job',
            'metadata_json' => json_encode(['origin' => 'legacy'], JSON_THROW_ON_ERROR),
        ]);

        DB::table('assistant_document_outputs')->insert([
            'assistant_document_id' => 'adoc_legacy',
            'bridge_document_id' => 'doc-legacy',
            'qdrant_collection' => 'hawki_legacy',
            'neo4j_namespace' => 'hawki_legacy',
            'chunk_count' => 4,
            'status' => 'indexed',
            'active' => true,
        ]);
    }

    private function insertManagedConflictState(int $outputId, string $deletedAt): void
    {
        DB::table('managed_documents')->insert([
            'document_id' => 'adoc_existing',
            'dataset_id' => 'managed-dataset',
            'display_name' => 'managed.pdf',
            'source_type' => 'upload',
            'status' => 'deleted',
            'deleted_at' => $deletedAt,
            'metadata_json' => json_encode(['origin' => 'managed'], JSON_THROW_ON_ERROR),
        ]);
        DB::table('managed_document_outputs')->insert([
            'id' => $outputId,
            'document_id' => 'adoc_existing',
            'bridge_document_id' => 'doc-existing',
            'qdrant_collection' => 'hawki_existing',
            'status' => 'deleted',
            'active' => false,
            'deleted_at' => $deletedAt,
            'metadata_json' => json_encode(['origin' => 'managed'], JSON_THROW_ON_ERROR),
        ]);
    }

    private function insertConflictingLegacyManagedDocumentData(): void
    {
        DB::table('assistant_documents')->insert([
            'assistant_document_id' => 'adoc_existing',
            'dataset_id' => 'stale-legacy-dataset',
            'display_name' => 'stale-legacy.pdf',
            'source_type' => 'upload',
            'graph_enabled' => true,
            'status' => 'indexed',
            'metadata_json' => json_encode(['origin' => 'stale-legacy'], JSON_THROW_ON_ERROR),
        ]);
        DB::table('assistant_document_outputs')->insert([
            'assistant_document_id' => 'adoc_existing',
            'bridge_document_id' => 'doc-existing',
            'qdrant_collection' => 'hawki_stale_legacy',
            'status' => 'indexed',
            'active' => true,
            'metadata_json' => json_encode(['origin' => 'stale-legacy'], JSON_THROW_ON_ERROR),
        ]);
    }

    private function insertLegacyMetadata(): void
    {
        $workflowMetadata = json_encode([
            'assistant_document_id' => 'adoc_legacy',
            'request' => [
                'metadata' => [
                    'assistant_document_id' => 'adoc_legacy',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        DB::table('pipeline_tasks')->insert(['task_id' => 'legacy-task', 'metadata' => $workflowMetadata]);
        DB::table('pipeline_jobs')->insert(['job_id' => 'legacy-job', 'metadata' => $workflowMetadata]);
        DB::table('ingestion_sources')->insert(['source_id' => 'legacy-source', 'metadata' => $workflowMetadata]);
        DB::table('documents')->insert([
            'id' => 'legacy-document',
            'metadata_json' => json_encode([
                'assistant_document_id' => 'adoc_legacy',
            ], JSON_THROW_ON_ERROR),
        ]);
    }

    private function assertCanonicalWorkflowMetadata(string $table, string $identifier, string $value): void
    {
        $metadata = $this->jsonValue(
            DB::table($table)->where($identifier, $value)->value('metadata'),
        );

        $this->assertSame('adoc_legacy', $metadata['managed_document_id']);
        $this->assertArrayNotHasKey('assistant_document_id', $metadata);
        $this->assertSame('adoc_legacy', $metadata['request']['metadata']['managed_document_id']);
        $this->assertArrayNotHasKey('assistant_document_id', $metadata['request']['metadata']);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function runMigration(string $filename): void
    {
        $migration = require database_path('migrations/'.$filename);

        if (! $migration instanceof Migration) {
            self::fail("Migration [{$filename}] did not return a Laravel migration instance.");
        }

        $up = [$migration, 'up'];

        if (! is_callable($up)) {
            self::fail("Migration [{$filename}] does not define a callable up method.");
        }

        $up();
    }

    private function withIsolatedPostgresSchema(callable $test): void
    {
        $database = $this->requiredEnvironmentValue('MIGRATION_TEST_DB_DATABASE');
        $allowSharedDatabase = filter_var(
            getenv('MIGRATION_TEST_ALLOW_SHARED_DATABASE') ?: false,
            FILTER_VALIDATE_BOOL,
        );

        if (! str_contains(strtolower($database), 'test') && ! $allowSharedDatabase) {
            self::fail(
                'MIGRATION_TEST_DB_DATABASE must contain "test", or '
                .'MIGRATION_TEST_ALLOW_SHARED_DATABASE=1 must explicitly allow an isolated schema in another database.',
            );
        }

        $schema = 'rawki_upgrade_test_'.getmypid().'_'.bin2hex(random_bytes(6));
        $controlConnection = 'migration_upgrade_control';
        $testConnection = 'migration_upgrade_pgsql';
        $originalConnection = (string) config('database.default');
        $schemaCreated = false;
        $baseConfig = [
            'driver' => 'pgsql',
            'url' => null,
            'host' => $this->requiredEnvironmentValue('MIGRATION_TEST_DB_HOST'),
            'port' => getenv('MIGRATION_TEST_DB_PORT') ?: '5432',
            'database' => $database,
            'username' => $this->requiredEnvironmentValue('MIGRATION_TEST_DB_USERNAME'),
            'password' => getenv('MIGRATION_TEST_DB_PASSWORD') ?: '',
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'sslmode' => getenv('MIGRATION_TEST_DB_SSLMODE') ?: 'prefer',
        ];

        config()->set("database.connections.{$controlConnection}", [
            ...$baseConfig,
            'search_path' => 'public',
        ]);

        try {
            DB::purge($controlConnection);
            DB::connection($controlConnection)->statement('CREATE SCHEMA '.$this->quoteIdentifier($schema));
            $schemaCreated = true;

            config()->set("database.connections.{$testConnection}", [
                ...$baseConfig,
                'search_path' => $schema,
            ]);
            config()->set('database.default', $testConnection);
            DB::purge($testConnection);
            DB::connection($testConnection)->getPdo();

            $test();
        } finally {
            config()->set('database.default', $originalConnection);
            DB::purge($testConnection);

            if ($schemaCreated) {
                DB::connection($controlConnection)->statement(
                    'DROP SCHEMA '.$this->quoteIdentifier($schema).' CASCADE',
                );
            }

            DB::purge($controlConnection);
            config()->offsetUnset("database.connections.{$testConnection}");
            config()->offsetUnset("database.connections.{$controlConnection}");
        }
    }

    private function requiredEnvironmentValue(string $name): string
    {
        $value = trim((string) (getenv($name) ?: ''));
        if ($value === '') {
            self::fail("Missing required PostgreSQL migration test environment variable [{$name}].");
        }

        return $value;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
