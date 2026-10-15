<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\Dataset;
use App\Models\IngestionSource;
use App\Models\ManagedDocument;
use App\Models\ManagedDocumentDeletion;
use App\Models\ManagedDocumentOutput;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\ManagedDocumentOutputDeletionService;
use App\Services\Document\ManagedDocumentRecoveryGuard;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Tasks\PipelineTaskService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\ManagedWriterFixture;
use Tests\TestCase;

final class ManagedDocumentWriterTest extends TestCase
{
    use DatabaseMigrations, ManagedWriterFixture;

    private string $workflowStatus = 'RUNNING';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWriter();
        Http::preventStrayRequests();
        Http::fake(fn ($request) => Http::response(['workflow_id' => $request->data()['workflow_id'], 'run_id' => 'delete-run',
            'status' => str_ends_with($request->url(), '/workflows/status') ? $this->workflowStatus : 'pending']));
    }

    public function test_writer_endpoint_requires_exact_body_authentication(): void
    {
        $this->postJson('/api/internal/pipeline/managed-writers', $this->writerEvent())->assertUnauthorized();
        $this->assertSame([], IngestionSource::query()->firstOrFail()->metadata['managed_writers'] ?? []);
    }

    public function test_selected_document_delete_fences_before_cancel_and_preserves_other_sources(): void
    {
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        IngestionSource::query()->firstOrFail()->update(['index_status' => 'running']);
        PipelineJob::query()->firstOrFail()->update(['status' => 'running']);
        $other = IngestionSource::query()->create(['source_id' => 'source-other', 'dataset_id' => 'writer-test',
            'task_id' => 'writer-task', 'source_url' => 'upload://other.pdf', 'index_status' => 'running']);
        PipelineJob::query()->create(['job_id' => 'other-job', 'task_id' => 'writer-task', 'source_id' => $other->source_id,
            'job_type' => 'ingest', 'status' => 'running', 'temporal_workflow_id' => 'other-workflow']);
        Http::fake(function ($request) {
            $this->assertSame('writer-workflow', $request['workflow_id']);
            $this->assertSame('closed', IngestionSource::query()->where('source_id', 'source-writer')->firstOrFail()->metadata['managed_writer_gate']);

            return Http::response([]);
        });
        $this->flushHeaders();
        $this->deleteJson('/api/documents/adoc_writer_test')->assertAccepted();
        $this->assertSame('running', $other->fresh()->index_status);
        $this->assertSame('running', PipelineJob::query()->where('job_id', 'other-job')->firstOrFail()->status);
        $this->assertSame('active', array_values(IngestionSource::query()->where('source_id', 'source-writer')->firstOrFail()->metadata['managed_writers'])[0]['status']);
        $this->signedWriter($this->writerEvent())->assertStatus(409);
        Http::assertSentCount(1);
    }

    public function test_dataset_archival_blocks_writer_acquisition_and_new_source_reservation(): void
    {
        Dataset::query()->where('dataset_id', 'writer-test')->update(['status' => 'archived']);
        $this->signedWriter($this->writerEvent())->assertStatus(409);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dataset is unavailable for new ingestion.');
        app(IngestionSourceRepository::class)->upsertStarting('late-source', ['dataset_id' => 'writer-test']);
    }

    public function test_public_task_cancel_keeps_gate_closed_after_lost_temporal_acknowledgement(): void
    {
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        IngestionSource::query()->firstOrFail()->update(['index_status' => 'running']);
        PipelineJob::query()->firstOrFail()->update(['status' => 'running']);
        Http::fake(function ($request) {
            $this->assertSame('closed', IngestionSource::query()->firstOrFail()->metadata['managed_writer_gate']);

            return (Http::failedConnection())($request);
        });
        $this->flushHeaders();
        $this->postJson('/api/pipeline/tasks/writer-task/cancel')->assertStatus(500);
        $source = IngestionSource::query()->firstOrFail();
        $this->assertSame('cancelled', $source->index_status);
        $this->assertSame('active', array_values($source->metadata['managed_writers'])[0]['status']);
        $this->signedWriter($this->writerEvent())->assertStatus(409);
        $this->assertDatabaseCount('managed_document_outputs', 1);
    }

    public function test_partial_outputs_are_registered_before_writes_and_missing_rows_are_reconciled(): void
    {
        $this->signedWriter($this->writerEvent('acquire', [$this->target(), $this->target('two.md')]))->assertOk();
        $this->assertDatabaseCount('managed_document_outputs', 2);
        $this->deleteJson('/api/documents/adoc_writer_test')->assertAccepted();
        $this->signedWriter($this->writerEvent('release'))->assertOk();
        ManagedDocumentOutput::query()->delete(); // Lost projection cannot erase the durable target registry.
        $this->deleteJson('/api/documents/adoc_writer_test')->assertAccepted();
        $targets = ManagedDocumentDeletion::query()->firstOrFail()->workflow_input['targets'];
        $this->assertCount(2, $targets);
        $this->assertSame('historic_collection', $targets[0]['collection']);
        $this->assertSame('historic_namespace', $targets[0]['neo4j_namespace']);
    }

    public function test_cancelled_or_uncertain_writers_block_deletion_and_other_attempts(): void
    {
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        IngestionSource::query()->firstOrFail()->update(['index_status' => 'cancelled']);
        $event = $this->writerEvent();
        $event['attempt'] = 2;
        $this->signedWriter($event)->assertStatus(409);
        $this->deleteJson('/api/documents/adoc_writer_test')->assertAccepted();
        $this->signedWriter($this->writerEvent('uncertain'))->assertOk()->assertJsonPath('status', 'uncertain');
        $this->signedWriter($this->writerEvent('release'))->assertOk()->assertJsonPath('status', 'uncertain');
        $this->deleteJson('/api/documents/adoc_writer_test')->assertAccepted();
        Http::assertNothingSent();
    }

    public function test_pending_delete_starts_cleanup_on_committed_writer_release_and_rejects_early_completion(): void
    {
        IngestionSource::query()->create(['source_id' => 'source-never-started', 'dataset_id' => 'writer-test', 'task_id' => 'writer-task',
            'source_url' => 'upload://never.pdf', 'index_status' => 'pending',
            'metadata' => ['request' => ['metadata' => ['managed_document_id' => 'adoc_writer_test']], 'managed_dispatch' => ['attempted' => false]]]);
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        $this->deleteJson('/api/documents/adoc_writer_test')->assertAccepted();
        Http::assertNothingSent();
        $operation = ManagedDocumentDeletion::query()->firstOrFail();
        $target = $operation->workflow_input['targets'][0];
        $event = ['schema_version' => 1, 'event_id' => 'drained-delete', 'operation_id' => $operation->operation_id,
            'workflow_id' => $operation->workflow_id, 'run_id' => 'delete-run', 'status' => 'completed', 'results' => [[
                'output_id' => $target['output_id'],
                'qdrant' => ['verified' => true, 'remaining_points' => 0, 'doc_id' => $target['doc_id'],
                    'collection' => $target['collection'], 'dataset_id' => 'writer-test', 'source_id' => 'source-writer'],
                'neo4j' => ['verified' => true, 'required' => true, 'remaining_contributions' => 0,
                    'doc_id' => $target['doc_id'], 'namespace' => $target['neo4j_namespace'], 'dataset_id' => 'writer-test'],
            ]]];
        $send = function () use ($event) {
            $body = json_encode($event, JSON_THROW_ON_ERROR);
            $time = (string) time();

            return $this->call('POST', '/api/internal/pipeline/managed-deletion-events', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HAWKI_TIMESTAMP' => $time, 'HTTP_X_HAWKI_SIGNATURE' => 'v1='.hash_hmac('sha256', $time.'.'.$body, 'writer-test-secret'),
            ], $body);
        };
        $send()->assertStatus(409);
        $this->assertDatabaseHas('managed_document_outputs', ['active' => true]);
        Http::fake(function ($request) use ($operation) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame($operation->workflow_id, $request['workflow_id']);
            $this->assertSame('released', array_values(IngestionSource::query()->firstOrFail()->metadata['managed_writers'])[0]['status']);

            return Http::response(['workflow_id' => $operation->workflow_id, 'run_id' => 'delete-run', 'status' => 'pending']);
        });
        $this->signedWriter($this->writerEvent('release'))->assertOk();
        Http::assertSentCount(1);
        $send()->assertOk();
        $this->assertDatabaseHas('managed_documents', ['document_id' => 'adoc_writer_test', 'status' => 'deleted']);
        $this->assertDatabaseHas('ingestion_sources', ['source_id' => 'source-never-started', 'index_status' => 'deleted']);
        $this->signedWriter($this->writerEvent())->assertStatus(409);
    }

    public function test_lost_cancel_ack_keeps_durable_delete_pending_until_writer_release(): void
    {
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        IngestionSource::query()->firstOrFail()->update(['index_status' => 'running']);
        PipelineJob::query()->firstOrFail()->update(['status' => 'running']);
        Http::fake(function ($request) {
            if (! str_ends_with($request->url(), '/temporal/workflows/cancel')) {
                return null;
            }
            $this->assertDatabaseHas('managed_document_deletions', ['status' => 'pending']);

            return (Http::failedConnection())($request);
        });
        $this->deleteJson('/api/documents/adoc_writer_test')->assertAccepted()->assertJsonPath('operation.status', 'pending');
        $operation = ManagedDocumentDeletion::query()->firstOrFail();
        $this->assertNotEmpty($operation->attempts['cancellation_error']);
        $this->assertNull($operation->run_id);
        $this->signedWriter($this->writerEvent('release'))->assertOk();
        $this->assertSame('delete-run', $operation->fresh()->run_id);
    }

    public function test_writer_identity_scope_and_gate_are_enforced(): void
    {
        $wrong = $this->writerEvent('acquire', [$this->target()]);
        $wrong['run_id'] = 'other-run';
        $this->signedWriter($wrong)->assertStatus(409);
        $wrong = $this->writerEvent('acquire', [['doc_id' => 'wrong-id', 'relative_path' => '../other.md']]);
        $this->signedWriter($wrong)->assertStatus(409);
        $this->assertDatabaseCount('managed_document_outputs', 0);
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        $this->signedWriter($this->writerEvent('release'))->assertOk();
        $this->deleteJson('/api/documents/adoc_writer_test')->assertAccepted();
        $this->signedWriter($this->writerEvent())->assertStatus(409);
    }

    public function test_vector_only_passthrough_records_effective_graph_scope_and_requires_graph_cleanup(): void
    {
        $source = IngestionSource::query()->firstOrFail();
        $metadata = $source->metadata;
        $metadata['managed_dispatch']['input']['ingestion']['graph'] = false;
        $source->update(['metadata' => $metadata]);
        $target = [...$this->target(), 'graph' => true];
        $this->signedWriter($this->writerEvent('acquire', [$target]))->assertOk()
            ->assertJsonPath('targets.0.neo4j_namespace', 'historic_namespace');
        $this->signedWriter($this->writerEvent('release'))->assertOk();
        $this->deleteJson('/api/documents/adoc_writer_test')->assertAccepted();
        $operation = ManagedDocumentDeletion::query()->firstOrFail();
        $scope = $operation->workflow_input['targets'][0];
        $this->assertSame('historic_namespace', $scope['neo4j_namespace']);
        $receipt = ['schema_version' => 1, 'event_id' => 'effective-graph', 'operation_id' => $operation->operation_id,
            'workflow_id' => $operation->workflow_id, 'run_id' => 'delete-run', 'status' => 'completed', 'results' => [[
                'output_id' => $scope['output_id'],
                'qdrant' => ['verified' => true, 'remaining_points' => 0, 'doc_id' => $scope['doc_id'], 'source_id' => $scope['source_id'], 'dataset_id' => 'writer-test', 'collection' => 'historic_collection'],
                'neo4j' => ['verified' => true, 'required' => false, 'remaining_contributions' => 0],
            ]]];
        $this->assertSame(409, $this->signedDeletion($receipt)->getStatusCode());
        $receipt['results'][0]['neo4j'] = ['verified' => true, 'required' => true, 'remaining_contributions' => 1,
            'dataset_id' => 'writer-test', 'namespace' => 'historic_namespace', 'doc_id' => $scope['doc_id']];
        $this->signedDeletion($receipt)->assertStatus(409);
        $this->assertNull(ManagedDocument::query()->firstOrFail()->deleted_at);
        $receipt['results'][0]['neo4j']['remaining_contributions'] = 0;
        $this->signedDeletion($receipt)->assertOk();
        $this->assertNotNull(ManagedDocument::query()->firstOrFail()->deleted_at);
    }

    public function test_effective_graph_grant_requires_frozen_namespace_and_never_downgrades(): void
    {
        $source = IngestionSource::query()->firstOrFail();
        $metadata = $source->metadata;
        $metadata['managed_dispatch']['input']['ingestion']['graph'] = false;
        $metadata['managed_dispatch']['input']['ingestion']['neo4j_namespace'] = null;
        $source->update(['metadata' => $metadata]);
        $this->signedWriter($this->writerEvent('acquire', [[...$this->target(), 'graph' => true]]))->assertStatus(409);
        $this->assertDatabaseCount('managed_document_outputs', 0);
        $metadata['managed_dispatch']['input']['ingestion']['neo4j_namespace'] = 'historic_namespace';
        $source->update(['metadata' => $metadata]);
        $this->signedWriter($this->writerEvent('acquire', [[...$this->target(), 'graph' => false]]))->assertOk();
        $this->signedWriter($this->writerEvent('acquire', [[...$this->target(), 'graph' => true]]))->assertOk();
        $this->signedWriter($this->writerEvent('acquire', [[...$this->target(), 'graph' => false]]))->assertOk()
            ->assertJsonPath('targets.0.neo4j_namespace', 'historic_namespace');
        $this->assertSame('historic_namespace', ManagedDocumentOutput::query()->firstOrFail()->neo4j_namespace);
    }

    public function test_pre_fix_vector_only_evidence_and_frozen_operations_fail_closed(): void
    {
        $source = IngestionSource::query()->firstOrFail();
        $metadata = $source->metadata;
        $metadata['managed_dispatch']['input']['ingestion']['graph'] = false;
        $source->update(['metadata' => $metadata]);
        // A pre-fix worker could silently enable graph after recording this target.
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        $this->signedWriter($this->writerEvent('release'))->assertOk();
        $this->deleteJson('/api/documents/adoc_writer_test')->assertStatus(409);
        Http::assertNothingSent();

        $operation = ManagedDocumentDeletion::query()->create(['operation_id' => 'delete_'.str_repeat('e', 64), 'document_id' => 'adoc_writer_test', 'dataset_id' => 'writer-test',
            'purpose' => 'delete', 'status' => 'pending', 'workflow_id' => 'managed-delete_'.str_repeat('e', 64), 'attempts' => ['writer_protocol' => 1],
            'workflow_input' => ['targets' => [['output_id' => 1, 'source_id' => 'source-writer', 'doc_id' => $this->target()['doc_id'], 'collection' => 'historic_collection', 'neo4j_namespace' => null]]]]);
        $this->deleteJson('/api/documents/adoc_writer_test')->assertStatus(409);
        $this->signedDeletion(['schema_version' => 1, 'event_id' => 'old-scope', 'operation_id' => $operation->operation_id, 'workflow_id' => $operation->workflow_id,
            'run_id' => 'old-run', 'status' => 'completed', 'results' => []])->assertStatus(409);
        $this->assertNull(ManagedDocument::query()->firstOrFail()->deleted_at);
        $root = storage_path('framework/testing/effective-graph-reconciliation-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($root);
        config()->set('temporal.storage.shared_root', $root);
        $manifest = $root.'/manifest.json';
        $this->workflowStatus = 'COMPLETED';
        try {
            File::put($manifest, json_encode([['document_id' => $this->target()['doc_id'], 'relative_path' => 'one.md']], JSON_THROW_ON_ERROR));
            $this->artisan('documents:reconcile-writer', ['sourceId' => 'source-writer', 'manifest' => $manifest, '--confirm-quiescence' => true])->assertExitCode(1);
            File::put($manifest, json_encode([['document_id' => $this->target()['doc_id'], 'relative_path' => 'one.md', 'graph' => true]], JSON_THROW_ON_ERROR));
            $operation->update(['run_id' => 'closed-old-run']);
            $this->artisan('documents:reconcile-writer', ['sourceId' => 'source-writer', 'manifest' => $manifest, '--confirm-quiescence' => true, '--retire-operation' => $operation->operation_id])->assertExitCode(0);
            $this->assertSame('abandoned', $operation->fresh()->status);
            $fresh = app(ManagedDocumentOutputDeletionService::class)->prepare(ManagedDocument::query()->firstOrFail(), 'delete', 'resolved-graph');
            $this->assertSame('historic_namespace', $fresh->workflow_input['targets'][0]['neo4j_namespace']);
            $this->assertTrue($fresh->attempts['effective_graph_scope']);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_legacy_snapshot_without_historical_evidence_cannot_dispatch_or_complete(): void
    {
        $target = ['output_id' => 1, 'source_id' => 'source-writer', 'doc_id' => $this->target()['doc_id'],
            'collection' => 'historic_collection', 'neo4j_namespace' => null];
        ManagedDocumentOutput::query()->create(['id' => 1, 'document_id' => 'adoc_writer_test', 'bridge_document_id' => $target['doc_id'],
            'source_id' => $target['source_id'], 'qdrant_collection' => $target['collection'], 'active' => true]);
        $operation = ManagedDocumentDeletion::query()->create(['operation_id' => 'delete_'.str_repeat('f', 64), 'document_id' => 'adoc_writer_test',
            'dataset_id' => 'writer-test', 'purpose' => 'delete', 'status' => 'pending', 'workflow_id' => 'managed-delete_'.str_repeat('f', 64),
            'attempts' => ['writer_protocol' => 1], 'workflow_input' => ['targets' => [$target]]]);
        $source = IngestionSource::query()->firstOrFail();
        $metadata = $source->metadata;
        unset($metadata['managed_dispatch']['input']['ingestion'], $metadata['dataset']);
        $source->update(['metadata' => $metadata]);
        $receipt = ['schema_version' => 1, 'event_id' => 'missing-historical-evidence', 'operation_id' => $operation->operation_id,
            'workflow_id' => $operation->workflow_id, 'run_id' => 'old-run', 'status' => 'completed',
            'results' => [['output_id' => 1, 'qdrant' => ['verified' => true, 'remaining_points' => 0, 'dataset_id' => 'writer-test',
                'source_id' => $target['source_id'], 'doc_id' => $target['doc_id'], 'collection' => $target['collection']],
                'neo4j' => ['verified' => true, 'remaining_contributions' => 0, 'required' => false]]]];

        foreach ([false, true] as $removeSource) {
            if ($removeSource) {
                $source->delete();
            }
            $this->deleteJson('/api/documents/adoc_writer_test')->assertStatus(409);
            $this->signedDeletion($receipt)->assertStatus(409);
            $this->assertSame('pending', $operation->fresh()->status);
            $this->assertNull(ManagedDocument::query()->firstOrFail()->deleted_at);
            $this->assertTrue(ManagedDocumentOutput::query()->firstOrFail()->active);
            Http::assertNothingSent();
        }
    }

    public function test_fresh_vector_only_snapshot_requires_effective_graph_evidence_and_explicit_reconciliation(): void
    {
        $document = ManagedDocument::query()->firstOrFail();
        $document->update(['graph_enabled' => false]);
        $source = IngestionSource::query()->firstOrFail();
        $metadata = $source->metadata;
        $metadata['managed_dispatch']['input']['ingestion']['graph'] = false;
        $source->update(['metadata' => $metadata]);
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        $this->signedWriter($this->writerEvent('release'))->assertOk();
        $metadata = $source->fresh()->metadata;
        unset($metadata['managed_dispatch']['input']['ingestion'], $metadata['dataset']);
        $source->update(['metadata' => $metadata]);

        $this->deleteJson('/api/documents/adoc_writer_test')->assertStatus(409);
        $this->assertDatabaseCount('managed_document_deletions', 0);
        $this->assertNull($document->fresh()->deleted_at);
        Http::assertNothingSent();

        $root = storage_path('framework/testing/fresh-graph-evidence-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($root);
        config()->set('temporal.storage.shared_root', $root);
        $metadata['managed_dispatch']['input']['ingestion'] = ['collection' => 'historic_collection', 'graph' => false, 'neo4j_namespace' => null];
        $source->update(['metadata' => $metadata]);
        $this->workflowStatus = 'COMPLETED';
        try {
            $manifest = $root.'/manifest.json';
            $record = ['document_id' => $this->target()['doc_id'], 'relative_path' => 'one.md'];
            File::put($manifest, json_encode([$record], JSON_THROW_ON_ERROR));
            $this->artisan('documents:reconcile-writer', ['sourceId' => 'source-writer', 'manifest' => $manifest, '--confirm-quiescence' => true])->assertExitCode(1);
            File::put($manifest, json_encode([[...$record, 'graph' => false]], JSON_THROW_ON_ERROR));
            $this->artisan('documents:reconcile-writer', ['sourceId' => 'source-writer', 'manifest' => $manifest, '--confirm-quiescence' => true])->assertExitCode(0);
            $operation = app(ManagedDocumentOutputDeletionService::class)->prepare($document->fresh(), 'delete', 'validated-vector-only');
            $this->assertNull($operation->workflow_input['targets'][0]['neo4j_namespace']);
            $this->assertTrue($operation->attempts['effective_graph_scope']);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_managed_task_history_can_be_hidden_without_blocking_on_writers_or_deletion(): void
    {
        $root = storage_path('framework/testing/managed-task-cleanup-'.bin2hex(random_bytes(8)));
        config()->set('temporal.storage.shared_root', $root);
        $paths = [$root.'/writer-task/upload.pdf', $root.'/writer-job/manifest.json', $root.'/sources/source-writer/raw/upload.pdf'];
        foreach ($paths as $path) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, 'owned evidence');
        }
        $source = IngestionSource::query()->firstOrFail();
        $source->update(['raw_storage_path' => $root.'/sources/source-writer/raw/', 'markdown_storage_path' => $root.'/sources/source-writer/markdown/']);
        $service = app(PipelineTaskService::class);
        try {
            $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
            foreach (['active', 'released', 'deleting'] as $state) {
                if ($state === 'released') {
                    $this->signedWriter($this->writerEvent('release'))->assertOk();
                } elseif ($state === 'deleting') {
                    $this->deleteJson('/api/documents/adoc_writer_test')->assertAccepted();
                }
                $result = $service->delete('writer-task');
                $this->assertTrue($result['deleted'], $state);
                $this->assertTrue($result['history_hidden'], $state);
                $this->assertTrue($result['storageCleanup']['ok'], $state);
                $this->assertTrue(PipelineTask::query()->firstOrFail()->metadata['history_hidden']);
                $this->assertSame([], $service->list());
                foreach ($paths as $path) {
                    $this->assertFileExists($path);
                }
                $this->assertDatabaseHas('ingestion_sources', ['source_id' => 'source-writer']);
                $this->assertDatabaseHas('pipeline_jobs', ['job_id' => 'writer-job']);
            }
            $operation = ManagedDocumentDeletion::query()->firstOrFail();
            $target = $operation->workflow_input['targets'][0];
            $this->signedDeletion(['schema_version' => 1, 'event_id' => 'verified-task-cleanup', 'operation_id' => $operation->operation_id,
                'workflow_id' => $operation->workflow_id, 'run_id' => 'delete-run', 'status' => 'completed', 'results' => [[
                    'output_id' => $target['output_id'],
                    'qdrant' => ['verified' => true, 'remaining_points' => 0, 'dataset_id' => 'writer-test', 'source_id' => 'source-writer', 'doc_id' => $target['doc_id'], 'collection' => 'historic_collection'],
                    'neo4j' => ['verified' => true, 'required' => true, 'remaining_contributions' => 0, 'dataset_id' => 'writer-test', 'namespace' => 'historic_namespace', 'doc_id' => $target['doc_id']],
                ]]])->assertOk();
            $this->assertTrue($service->delete('writer-task')['deleted']);
            foreach ($paths as $path) {
                $this->assertFileExists($path);
            }
            $this->assertDatabaseHas('ingestion_sources', ['source_id' => 'source-writer']);
            $this->assertDatabaseHas('pipeline_jobs', ['job_id' => 'writer-job']);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_validated_vector_only_registration_can_prepare_without_graph_cleanup(): void
    {
        $source = IngestionSource::query()->firstOrFail();
        $metadata = $source->metadata;
        $metadata['managed_dispatch']['input']['ingestion']['graph'] = false;
        $metadata['managed_dispatch']['input']['ingestion']['neo4j_namespace'] = null;
        $source->update(['metadata' => $metadata]);
        $this->signedWriter($this->writerEvent('acquire', [[...$this->target(), 'graph' => false]]))->assertOk();
        $this->signedWriter($this->writerEvent('release'))->assertOk();
        $this->deleteJson('/api/documents/adoc_writer_test')->assertAccepted();
        $operation = ManagedDocumentDeletion::query()->firstOrFail();
        $this->assertNull($operation->workflow_input['targets'][0]['neo4j_namespace']);
        $this->assertTrue($operation->attempts['effective_graph_scope']);
    }

    public function test_managed_task_history_can_be_hidden_when_source_evidence_is_missing(): void
    {
        $root = storage_path('framework/testing/missing-managed-cleanup-'.bin2hex(random_bytes(8)));
        config()->set('temporal.storage.shared_root', $root);
        $path = $root.'/writer-task/manifest.json';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'owned evidence');
        PipelineTask::query()->firstOrFail()->update(['metadata' => ['request' => ['metadata' => ['managed_document_id' => 'adoc_writer_test']]]]);
        IngestionSource::query()->delete();
        try {
            $this->assertTrue(app(PipelineTaskService::class)->delete('writer-task')['deleted']);
            $this->assertTrue(PipelineTask::query()->firstOrFail()->metadata['history_hidden']);
            $this->assertFileExists($path);
            $this->assertDatabaseHas('pipeline_jobs', ['job_id' => 'writer-job']);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_hidden_task_flag_survives_stale_recovery_and_does_not_block_writer_release(): void
    {
        $task = PipelineTask::query()->firstOrFail();
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        $this->deleteJson('/api/pipeline/tasks/writer-task')->assertOk()->assertJsonPath('history_hidden', true);
        $this->getJson('/api/pipeline/tasks')->assertOk()->assertJsonCount(0, 'tasks');
        app(PipelineTaskRepository::class)->markRecoveryRunning($task, ['retry_count' => 1]);
        $this->assertTrue($task->fresh()->metadata['history_hidden']);
        $this->getJson('/api/pipeline/tasks')->assertJsonCount(0, 'tasks');
        $this->deleteJson('/api/pipeline/tasks/writer-task')->assertOk()->assertJsonPath('history_hidden', true);
        $this->signedWriter($this->writerEvent('release'))->assertOk();
        $this->assertNull(ManagedDocument::query()->firstOrFail()->deleted_at);
        $this->assertTrue(ManagedDocumentOutput::query()->firstOrFail()->active);
        Http::assertNothingSent();
    }

    private function signedDeletion(array $event)
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $time = (string) time();

        return $this->call('POST', '/api/internal/pipeline/managed-deletion-events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_HAWKI_TIMESTAMP' => $time,
            'HTTP_X_HAWKI_SIGNATURE' => 'v1='.hash_hmac('sha256', $time.'.'.$body, 'writer-test-secret')], $body);
    }

    public function test_acknowledgement_preserves_registration_and_historical_runs(): void
    {
        $stale = IngestionSource::query()->firstOrFail();
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        app(IngestionSourceRepository::class)->markWorkflowStarted($stale, 'writer-workflow', 'writer-run', null);
        $this->assertCount(1, $stale->fresh()->metadata['managed_writers']);
        $this->signedWriter($this->writerEvent('release'))->assertOk();
        app(IngestionSourceRepository::class)->recordPlannedWorkflow($stale->fresh(), 'recovery-workflow', ['ingestion' => ['collection' => 'historic_collection']]);
        $this->assertContains(['workflow_id' => 'writer-workflow', 'run_id' => 'writer-run'], $stale->fresh()->metadata['managed_writer_history']);
    }

    public function test_recovery_cannot_start_while_original_workflow_is_retrying(): void
    {
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        $this->signedWriter($this->writerEvent('release'))->assertOk();
        // Status is read from Temporal separately from the durable sink permission.
        $this->expectException(ManagedDeletionException::class);
        $this->expectExceptionMessage('Original managed workflow is still retrying');
        app(ManagedDocumentRecoveryGuard::class)->verify(IngestionSource::query()->firstOrFail());
    }

    public function test_recovery_checks_each_distinct_historical_run_once(): void
    {
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        $this->signedWriter($this->writerEvent('release'))->assertOk();
        $source = IngestionSource::query()->firstOrFail();
        $metadata = $source->metadata;
        $metadata['managed_writer_history']['older|older-run'] = ['workflow_id' => 'older', 'run_id' => 'older-run'];
        $source->metadata = $metadata;
        $this->workflowStatus = 'COMPLETED';

        app(ManagedDocumentRecoveryGuard::class)->verify($source);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['workflow_id'] === 'writer-workflow' && $request['run_id'] === 'writer-run');
        Http::assertSent(fn ($request) => $request['workflow_id'] === 'older' && $request['run_id'] === 'older-run');
    }

    public function test_recovery_reservation_keeps_old_runs_and_scope_and_rejects_competing_reservation(): void
    {
        $this->signedWriter($this->writerEvent('acquire', [$this->target()]))->assertOk();
        $this->signedWriter($this->writerEvent('release'))->assertOk();
        $source = IngestionSource::query()->firstOrFail();
        $job = PipelineJob::query()->firstOrFail();
        $repository = app(IngestionSourceRepository::class);
        $reserved = $repository->reserveManagedRecovery($source, $job, 'recovery-one', ['ingestion' => ['collection' => 'new_configuration']]);
        $this->assertSame('historic_collection', $reserved->metadata['managed_dispatch']['input']['ingestion']['collection']);
        $this->assertCount(1, $reserved->metadata['managed_writers']);
        $this->assertContains(['workflow_id' => 'writer-workflow', 'run_id' => 'writer-run'], $reserved->metadata['managed_writer_history']);
        $this->signedWriter($this->writerEvent())->assertStatus(409);
        $event = $this->writerEvent();
        $event['workflow_id'] = 'recovery-one';
        $event['run_id'] = 'recovery-run';
        $this->signedWriter($event)->assertOk();
        $this->expectException(\RuntimeException::class);
        $repository->reserveManagedRecovery($source, $job, 'recovery-two', ['ingestion' => []]);
    }

    public function test_reconciliation_requires_physical_attestation_and_valid_owned_manifest(): void
    {
        $root = storage_path('framework/testing/writer-reconciliation-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($root);
        config()->set('temporal.storage.shared_root', $root);
        $manifest = $root.'/manifest.json';
        File::put($manifest, '{broken');
        // Override the generic test fake by resetting the HTTP factory.
        $this->workflowStatus = 'CANCELED';
        try {
            $this->artisan('documents:reconcile-writer', ['sourceId' => 'source-writer', 'manifest' => $manifest])->assertExitCode(1);
            $this->assertArrayNotHasKey('managed_writer_gate', IngestionSource::query()->firstOrFail()->metadata);
            $this->artisan('documents:reconcile-writer', ['sourceId' => 'source-writer', 'manifest' => $manifest, '--confirm-quiescence' => true])->assertExitCode(1);
            $this->assertSame('closed', IngestionSource::query()->firstOrFail()->metadata['managed_writer_gate']);
            $this->assertArrayNotHasKey('managed_target_manifest_complete', IngestionSource::query()->firstOrFail()->metadata);
            File::put($manifest, json_encode([['document_id' => $this->target()['doc_id'], 'relative_path' => 'one.md']], JSON_THROW_ON_ERROR));
            $this->artisan('documents:reconcile-writer', ['sourceId' => 'source-writer', 'manifest' => $manifest, '--confirm-quiescence' => true])->assertExitCode(0);
            $this->assertSame('historic_collection', ManagedDocumentOutput::query()->firstOrFail()->qdrant_collection);
            $this->assertTrue(IngestionSource::query()->firstOrFail()->metadata['managed_target_manifest_complete']);
            $this->signedWriter($this->writerEvent())->assertStatus(409);
            $operation = app(ManagedDocumentOutputDeletionService::class)->prepare(ManagedDocument::query()->firstOrFail(), 'delete', 'old-operation');
            $operation->update(['attempts' => null, 'run_id' => 'closed-deletion-run']);
            $this->deleteJson('/api/documents/adoc_writer_test')->assertStatus(409);
            $this->artisan('documents:reconcile-writer', ['sourceId' => 'source-writer', 'manifest' => $manifest, '--confirm-quiescence' => true, '--retire-operation' => $operation->operation_id])->assertExitCode(0);
            $this->assertSame('abandoned', $operation->fresh()->status);
            $fresh = app(ManagedDocumentOutputDeletionService::class)->prepare(ManagedDocument::query()->firstOrFail(), 'delete', 'new-operation');
            $this->assertNotSame($operation->operation_id, $fresh->operation_id);
            $this->assertCount(1, $fresh->workflow_input['targets']);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
