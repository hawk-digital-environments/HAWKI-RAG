<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Models\IngestionSource;
use App\Models\ManagedDocument;
use App\Models\ManagedDocumentDeletion;
use App\Models\ManagedDocumentOutput;
use App\Services\Authorization\DatasetIngestionAuthorizationService;
use App\Services\Dataset\DatasetService;
use App\Services\Pipeline\Clients\PythonTemporalBridgeClient;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\NullLogger;
use Tests\TestCase;

class ManagedDocumentDeletionTest extends TestCase
{
    use DatabaseMigrations;

    private bool $loseDeletionAck = false;

    private bool $loseUploadAck = false;

    private string $deletionRun = 'delete-run';

    private ?\Closure $beforeDeletionAck = null;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('temporal.callbacks.secret', 'managed-test-secret');
        $user = $this->actingAsApiUser();
        $dataset = Dataset::query()->create(['dataset_id' => 'delete-test', 'name' => 'delete-test', 'status' => 'active', 'qdrant_collection' => 'test_collection', 'neo4j_namespace' => 'test_namespace']);
        app(DatasetIngestionAuthorizationService::class)->grantAccess($user, $dataset);
        ManagedDocument::query()->create(['document_id' => 'adoc_delete_test', 'dataset_id' => 'delete-test', 'source_type' => 'upload', 'status' => 'indexed', 'graph_enabled' => true, 'latest_source_id' => 'source-old']);
        IngestionSource::query()->create(['source_id' => 'source-old', 'dataset_id' => 'delete-test', 'source_url' => 'upload://old.pdf', 'index_status' => 'ready', 'metadata' => ['request' => ['metadata' => ['managed_document_id' => 'adoc_delete_test']], 'managed_writer_protocol' => 1, 'managed_writer_gate' => 'closed', 'managed_target_manifest_complete' => true]]);
        ManagedDocumentOutput::query()->create(['document_id' => 'adoc_delete_test', 'bridge_document_id' => 'doc-shared-id', 'source_id' => 'source-old', 'qdrant_collection' => 'historic_collection', 'neo4j_namespace' => 'historic_namespace', 'active' => true, 'status' => 'indexed']);
        Http::preventStrayRequests();
        $this->pendingBridge();
    }

    public function test_pending_duplicate_and_signed_completion_are_durable(): void
    {
        $first = $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted();
        $this->deleteJson('/api/documents/adoc_delete_test', [], ['Idempotency-Key' => 'another-key'])->assertAccepted()->assertJsonPath('operation.operation_id', $first->json('operation.operation_id'));
        $this->assertDatabaseCount('managed_document_deletions', 1);
        $this->assertDatabaseHas('managed_document_outputs', ['active' => true]);
        $this->getJson('/api/documents/adoc_delete_test')->assertOk()->assertJsonPath('document.status', 'deleting');
        $operation = ManagedDocumentDeletion::query()->firstOrFail();
        $this->assertSame('historic_collection', $operation->workflow_input['targets'][0]['collection']);
        $this->sendReceipt($this->receipt($operation))->assertOk()->assertJsonPath('status', 'completed');
        $this->sendReceipt($this->receipt($operation))->assertOk();
        $this->sendReceipt($this->receipt($operation, 'failed'))->assertOk()->assertJsonPath('status', 'completed');
        $this->deleteJson('/api/documents/adoc_delete_test')->assertOk()->assertJsonPath('document.status', 'deleted');
        $this->assertDatabaseHas('managed_document_outputs', ['active' => false, 'status' => 'deleted']);
        Http::assertSentCount(2);
    }

    public function test_lost_start_acknowledgement_reuses_the_same_operation(): void
    {
        $this->loseDeletionAck = true;
        $first = $this->deleteJson('/api/documents/adoc_delete_test')->assertStatus(502);
        $this->assertDatabaseHas('managed_documents', ['status' => 'deleting', 'deleted_at' => null]);
        $this->loseDeletionAck = false;
        $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted()->assertJsonPath('operation.operation_id', $first->json('operation.operation_id'));
        $this->assertDatabaseCount('managed_document_deletions', 1);
    }

    public function test_delete_retains_trimmed_request_key_and_unknown_mutations_return_not_found(): void
    {
        $this->deleteJson('/api/documents/adoc_delete_test', [], ['Idempotency-Key' => '  retry-key  '])->assertAccepted();
        $this->assertSame(hash('sha256', 'retry-key'), ManagedDocumentDeletion::query()->firstOrFail()->request_key);
        $this->deleteJson('/api/documents/adoc_missing')->assertNotFound();
        $this->putJson('/api/documents/adoc_missing', [])->assertNotFound();
    }

    #[TestWith([['workflow_id' => 'another-workflow'], 'unexpected identity'])]
    #[TestWith(['invalid-json', 'invalid JSON'])]
    public function test_shared_bridge_transport_rejects_invalid_deletion_acknowledgements(array|string $response, string $message): void
    {
        $http = new HttpFactory;
        $http->fake(fn () => HttpFactory::response($response));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);

        (new PythonTemporalBridgeClient(config(), new NullLogger, $http))->deleteOutputs('managed-test', []);
    }

    #[TestWith(['temporal.callbacks.secret', '', 'not configured'])]
    #[TestWith(['temporal.enabled', false, 'disabled'])]
    public function test_deletion_cannot_dispatch_without_authenticated_temporal(string $setting, string|bool $value, string $message): void
    {
        config()->set($setting, $value);
        $this->deleteJson('/api/documents/adoc_delete_test')->assertStatus(502)->assertJsonPath('document.status', 'deleting');
        $this->assertStringContainsString($message, ManagedDocumentDeletion::query()->firstOrFail()->last_error);
        $this->assertTrue(ManagedDocumentOutput::query()->firstOrFail()->active);
        Http::assertNothingSent();
    }

    public function test_failed_sink_evidence_keeps_outputs_active_and_same_retry_identity(): void
    {
        $first = $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted();
        $operation = ManagedDocumentDeletion::query()->firstOrFail();
        $this->sendReceipt($this->receipt($operation, 'failed'))->assertOk();
        $this->assertDatabaseHas('managed_document_outputs', ['active' => true]);
        $this->assertDatabaseHas('managed_documents', ['status' => 'deleting']);
        $this->deleteJson('/api/documents/adoc_delete_test')->assertStatus(502)->assertJsonPath('operation.operation_id', $first->json('operation.operation_id'));
        $this->sendReceipt($this->receipt($operation))->assertOk();
    }

    public function test_partial_or_wrong_scope_completion_is_rejected(): void
    {
        $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted();
        $operation = ManagedDocumentDeletion::query()->firstOrFail();
        $receipt = $this->receipt($operation);
        $receipt['results'][0]['qdrant']['remaining_points'] = 1;
        $this->sendReceipt($receipt)->assertStatus(409);
        $receipt = $this->receipt($operation);
        $receipt['results'][0]['neo4j']['namespace'] = 'another-dataset';
        $this->sendReceipt($receipt)->assertStatus(409);
        $receipt['results'] = [];
        $this->sendReceipt($receipt)->assertStatus(409);
        $this->assertDatabaseHas('managed_document_outputs', ['active' => true]);
    }

    public function test_unsigned_callback_cannot_change_metadata(): void
    {
        $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted();
        $this->postJson('/api/internal/pipeline/managed-deletion-events', $this->receipt(ManagedDocumentDeletion::query()->firstOrFail()))->assertUnauthorized();
        $this->assertDatabaseHas('managed_document_outputs', ['active' => true]);
    }

    #[TestWith([1728, true])]
    #[TestWith([2001, false])]
    public function test_large_document_cleanup_retains_bounded_complete_evidence(int $count, bool $accepted): void
    {
        $rows = [];
        for ($index = 1; $index < $count; $index++) {
            $rows[] = ['document_id' => 'adoc_delete_test', 'bridge_document_id' => 'doc-large-'.$index,
                'source_id' => 'source-old', 'qdrant_collection' => 'historic_collection',
                'neo4j_namespace' => 'historic_namespace', 'active' => true, 'status' => 'indexed'];
        }
        ManagedDocumentOutput::query()->insert($rows);
        if (! $accepted) {
            $this->deleteJson('/api/documents/adoc_delete_test')->assertStatus(409);
            Http::assertNothingSent();

            return;
        }
        $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted();
        $operation = ManagedDocumentDeletion::query()->firstOrFail();
        $this->assertCount($count, $operation->workflow_input['targets']);
        $this->sendReceipt($this->receipt($operation))->assertOk();
        $this->assertSame(0, ManagedDocumentOutput::query()->where('active', true)->count());
        $this->assertDatabaseHas('managed_documents', ['status' => 'deleted']);
    }

    public function test_management_delete_needs_no_token_or_dataset_ingest_grant(): void
    {
        $this->flushHeaders();
        DatasetGrant::query()->delete();
        $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted();
        $this->assertDatabaseCount('managed_document_deletions', 1);
        $this->assertTrue(ManagedDocumentOutput::query()->firstOrFail()->active);
        $this->deleteJson('/api/documents/adoc_missing')->assertNotFound();
    }

    public function test_dataset_delete_archives_then_reuses_verified_document_cleanup(): void
    {
        $this->flushHeaders();
        $this->deleteJson('/api/datasets/delete-test/storage')->assertAccepted()->assertJsonPath('success', true);
        Http::assertNothingSent();
        app(DatasetService::class)->resumeArchivedDeletions();
        $this->assertDatabaseHas('datasets', ['dataset_id' => 'delete-test', 'status' => Dataset::STATUS_ARCHIVED]);
        $operation = ManagedDocumentDeletion::query()->firstOrFail();
        $this->sendReceipt($this->receipt($operation))->assertOk();
        config()->set('config.qdrant_http_url', 'http://qdrant.test');
        config()->set('config.neo4j_http_url', 'http://neo4j.test');
        Http::fake([
            'http://qdrant.test/*/points/count' => Http::response(['result' => ['count' => 0]]),
            'http://neo4j.test/*' => Http::response(['results' => [['data' => [['row' => [0]]]], ['data' => [['row' => [0]]]]], 'errors' => []]),
        ]);
        app(DatasetService::class)->resumeArchivedDeletions();
        $this->deleteJson('/api/datasets/delete-test/storage')->assertOk()->assertJsonPath('success', true);
        $this->assertDatabaseHas('datasets', ['dataset_id' => 'delete-test', 'status' => Dataset::STATUS_DELETED]);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE');
    }

    public function test_dataset_delete_resumes_pending_replacement_cleanup_without_starting_ingestion(): void
    {
        $root = storage_path('framework/testing/dataset-delete-replacement');
        config()->set('temporal.storage.shared_root', $root);
        config()->set('file_converter.raganything_supported_extensions', ['pdf']);
        try {
            $this->put('/api/documents/adoc_delete_test', ['force' => true,
                'file' => UploadedFile::fake()->createWithContent('new.pdf', 'replacement bytes')], ['Accept' => 'application/json'])->assertAccepted();
            $replacement = ManagedDocumentDeletion::query()->firstOrFail();
            $this->deleteJson('/api/datasets/delete-test/storage')->assertAccepted();
            app(DatasetService::class)->resumeArchivedDeletions();
            $this->sendReceipt($this->receipt($replacement))->assertOk();
            $this->deleteJson('/api/datasets/delete-test/storage')->assertAccepted();
            app(DatasetService::class)->resumeArchivedDeletions();
            $this->assertSame('abandoned', $replacement->fresh()->status);
            $deletion = ManagedDocumentDeletion::query()->where('purpose', 'delete')->firstOrFail();
            $this->sendReceipt($this->receipt($deletion))->assertOk();
            $this->assertDatabaseHas('managed_documents', ['status' => 'deleted']);
            Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/workflows/ingest'));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_source_ownership_and_writer_barriers_fail_closed(): void
    {
        $source = IngestionSource::query()->firstOrFail();
        $source->update(['index_status' => 'running']);
        $this->deleteJson('/api/documents/adoc_delete_test')->assertStatus(409);
        $source->update(['index_status' => 'ready', 'metadata' => ['request' => ['managed_document_id' => 'adoc_other']]]);
        $this->deleteJson('/api/documents/adoc_delete_test')->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_empty_output_snapshot_still_completes_through_orchestration(): void
    {
        ManagedDocumentOutput::query()->delete();
        $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted();
        $operation = ManagedDocumentDeletion::query()->firstOrFail();
        $this->sendReceipt($this->receipt($operation))->assertOk();
        $this->assertDatabaseHas('managed_documents', ['status' => 'deleted']);
    }

    public function test_deleted_source_cannot_be_restarted_by_pipeline_recovery(): void
    {
        $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Managed deletion prevents restarting');
        app(IngestionSourceRepository::class)->upsertStarting('source-old', []);
    }

    public function test_update_waits_for_cleanup_and_resumes_one_replacement_task(): void
    {
        $this->flushHeaders();
        DatasetGrant::query()->delete();
        $root = storage_path('framework/testing/managed-delete-replacement');
        File::deleteDirectory($root);
        config()->set('temporal.storage.shared_root', $root);
        config()->set('file_converter.raganything_supported_extensions', ['pdf']);
        $input = fn () => ['force' => true, 'file' => UploadedFile::fake()->createWithContent('replacement.pdf', 'same replacement bytes')];
        $headers = ['Accept' => 'application/json', 'Idempotency-Key' => 'update-once'];
        try {
            $this->put('/api/documents/adoc_delete_test', $input(), $headers)->assertAccepted()->assertJsonPath('operation.status', 'pending');
            $this->assertDatabaseCount('pipeline_tasks', 1); // Preparation is durable before cleanup; no ingestion is dispatched yet.
            $operation = ManagedDocumentDeletion::query()->firstOrFail();
            $changed = ['force' => true, 'file' => UploadedFile::fake()->createWithContent('replacement.pdf', 'different bytes')];
            $this->put('/api/documents/adoc_delete_test', $changed, $headers)->assertStatus(409);
            $this->deleteJson('/api/documents/adoc_delete_test')->assertStatus(409);
            $this->sendReceipt($this->receipt($operation))->assertOk();
            $this->loseUploadAck = true;
            $this->put('/api/documents/adoc_delete_test', $input(), $headers)->assertStatus(502);
            $this->assertDatabaseCount('pipeline_tasks', 1);
            $this->loseUploadAck = false;
            $response = $this->put('/api/documents/adoc_delete_test', $input(), $headers)->assertAccepted();
            $taskId = $response->json('pipeline.task_id');
            $this->put('/api/documents/adoc_delete_test', $input(), $headers)->assertAccepted()->assertJsonPath('pipeline.task_id', $taskId);
            $this->assertDatabaseCount('pipeline_tasks', 1);
            $this->assertDatabaseCount('pipeline_jobs', 1);
            $this->assertDatabaseHas('managed_document_deletions', ['status' => 'continued']);
            Http::assertSent(fn ($request) => str_ends_with($request->url(), '/workflows/ingest') && ($request->data()['reuse_completed'] ?? false) === true && $request->hasHeader('X-Hawki-Signature'));
            $replacementSource = IngestionSource::query()->where('source_id', $response->json('pipeline.source_id'))->firstOrFail();
            $this->assertSame('adoc_delete_test', $replacementSource->metadata['request']['metadata']['managed_document_id']);
            $this->sendReceipt($this->receipt($operation))->assertOk()->assertJsonPath('status', 'continued');
            $this->assertDatabaseHas('managed_documents', ['deleted_at' => null, 'latest_task_id' => $taskId]);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_real_upload_metadata_and_identical_files_have_independent_owners(): void
    {
        $root = storage_path('framework/testing/managed-deletion-real-upload');
        File::deleteDirectory($root);
        config()->set('temporal.storage.shared_root', $root);
        config()->set('file_converter.raganything_supported_extensions', ['pdf']);
        try {
            $input = fn () => ['dataset_id' => 'delete-test', 'file' => UploadedFile::fake()->createWithContent('same.pdf', 'same bytes')];
            $first = $this->post('/api/documents', $input(), ['Accept' => 'application/json'])->assertAccepted();
            $second = $this->post('/api/documents', $input(), ['Accept' => 'application/json'])->assertAccepted();
            $firstDoc = ManagedDocument::query()->findOrFail($first->json('document.document_id'));
            $secondDoc = ManagedDocument::query()->findOrFail($second->json('document.document_id'));
            $this->assertNotSame($firstDoc->latest_source_id, $secondDoc->latest_source_id);
            $source = IngestionSource::query()->where('source_id', $firstDoc->latest_source_id)->firstOrFail();
            $this->assertSame($firstDoc->document_id, $source->metadata['request']['metadata']['managed_document_id']);
            $metadata = $source->metadata;
            $metadata['managed_writer_protocol'] = 1;
            $metadata['managed_target_manifest_complete'] = true;
            $metadata['managed_writer_gate'] = 'closed';
            $source->update(['index_status' => 'ready', 'metadata' => $metadata]);
            ManagedDocumentOutput::query()->create(['document_id' => $firstDoc->document_id, 'bridge_document_id' => 'doc-real-upload', 'source_id' => $source->source_id, 'qdrant_collection' => 'test_collection', 'neo4j_namespace' => 'test_namespace', 'active' => true]);
            $this->deleteJson('/api/documents/'.$firstDoc->document_id)->assertAccepted();
            $operation = ManagedDocumentDeletion::query()->where('document_id', $firstDoc->document_id)->firstOrFail();
            $this->assertSame($source->source_id, $operation->workflow_input['targets'][0]['source_id']);
            $this->assertSame('replacement-run', $operation->workflow_input['writers'][0]['run_id']);
            $this->assertDatabaseHas('ingestion_sources', ['source_id' => $secondDoc->latest_source_id, 'index_status' => 'running']);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_an_older_continued_operation_cannot_hide_a_pending_operation(): void
    {
        $response = $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted();
        $operation = ManagedDocumentDeletion::query()->firstOrFail();
        ManagedDocumentDeletion::query()->create([
            'operation_id' => 'delete_'.str_repeat('f', 64), 'document_id' => $operation->document_id,
            'dataset_id' => $operation->dataset_id, 'purpose' => 'update', 'status' => 'continued',
            'workflow_id' => 'old-workflow', 'workflow_input' => [], 'created_at' => $operation->created_at,
        ]);
        $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted()->assertJsonPath('operation.operation_id', $response->json('operation.operation_id'));
        $this->assertDatabaseCount('managed_document_deletions', 2);
    }

    public function test_completion_verifies_dataset_source_and_missing_metadata_is_idempotent(): void
    {
        $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted();
        $operation = ManagedDocumentDeletion::query()->firstOrFail();
        $wrongDataset = $this->receipt($operation);
        $wrongDataset['results'][0]['qdrant']['dataset_id'] = 'another-dataset';
        $this->sendReceipt($wrongDataset)->assertStatus(409);
        $wrongSource = $this->receipt($operation);
        $wrongSource['results'][0]['qdrant']['source_id'] = 'another-source';
        $this->sendReceipt($wrongSource)->assertStatus(409);
        ManagedDocumentOutput::query()->delete();
        $this->sendReceipt($this->receipt($operation))->assertOk()->assertJsonPath('status', 'completed');
        $this->assertDatabaseHas('managed_documents', ['status' => 'deleted']);
    }

    public function test_malformed_signed_callback_is_rejected_before_metadata_changes(): void
    {
        $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted();
        $receipt = $this->receipt(ManagedDocumentDeletion::query()->firstOrFail());
        $receipt['results'] = ['not-output-proof'];
        $this->sendReceipt($receipt)->assertUnprocessable();
        $this->assertDatabaseHas('managed_document_outputs', ['active' => true]);
    }

    public function test_unconfirmed_ingestion_start_cannot_be_deleted(): void
    {
        IngestionSource::query()->firstOrFail()->update(['index_status' => 'failed', 'temporal_workflow_id' => 'planned-without-ack']);
        $this->deleteJson('/api/documents/adoc_delete_test')->assertStatus(409);
        $this->assertDatabaseCount('managed_document_deletions', 0);
        Http::assertNothingSent();
    }

    public function test_unsupported_replacement_preserves_content_without_dispatch(): void
    {
        config()->set('file_converter.raganything_supported_extensions', ['pdf']);
        $this->put('/api/documents/adoc_delete_test', ['force' => true, 'file' => UploadedFile::fake()->createWithContent('invalid.exe', 'invalid')], ['Accept' => 'application/json'])->assertStatus(422);
        Http::assertNothingSent();
        $this->assertDatabaseCount('managed_document_deletions', 0);
        $this->assertDatabaseHas('managed_document_outputs', ['active' => true]);
        $this->assertDatabaseHas('managed_documents', ['status' => 'indexed']);
    }

    public function test_failed_callback_before_pending_acknowledgement_is_monotonic(): void
    {
        $this->beforeDeletionAck = function ($request) {
            $operation = ManagedDocumentDeletion::query()->firstOrFail();
            $this->sendReceipt($this->receipt($operation, 'failed'))->assertOk();
        };
        $this->deleteJson('/api/documents/adoc_delete_test')->assertStatus(502)->assertJsonPath('operation.status', 'failed');
        $this->assertStringContainsString('Sink cleanup failed', ManagedDocumentDeletion::query()->firstOrFail()->last_error);
    }

    public function test_completed_callback_before_pending_acknowledgement_is_monotonic(): void
    {
        $this->beforeDeletionAck = function ($request) {
            $operation = ManagedDocumentDeletion::query()->firstOrFail();
            $this->sendReceipt($this->receipt($operation))->assertOk();
        };
        $this->deleteJson('/api/documents/adoc_delete_test')->assertOk()->assertJsonPath('operation.status', 'completed');
    }

    public function test_new_run_can_recover_but_a_superseded_pending_ack_cannot_replace_it(): void
    {
        $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted();
        $operation = ManagedDocumentDeletion::query()->firstOrFail();
        $this->sendReceipt($this->receipt($operation, 'failed'))->assertOk();
        $this->deletionRun = 'new-run';
        $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted()->assertJsonPath('operation.run_id', 'new-run');
        $this->deletionRun = 'delete-run';
        $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted()->assertJsonPath('operation.run_id', 'new-run');
    }

    public function test_failed_source_without_writer_evidence_cannot_prove_empty_sinks(): void
    {
        ManagedDocumentOutput::query()->delete();
        $source = IngestionSource::query()->firstOrFail();
        $metadata = $source->metadata;
        unset($metadata['managed_writer_protocol']);
        $source->update(['index_status' => 'failed', 'metadata' => $metadata, 'temporal_workflow_id' => 'failed-writer']);
        $this->deleteJson('/api/documents/adoc_delete_test')->assertStatus(409);
        Http::assertNothingSent();
        $this->assertDatabaseHas('managed_documents', ['deleted_at' => null]);
    }

    public function test_cancelled_writer_with_unreleased_permission_blocks_cleanup(): void
    {
        $source = IngestionSource::query()->firstOrFail();
        $metadata = $source->metadata;
        $metadata['managed_writers'] = ['old' => ['workflow_id' => 'old-workflow', 'run_id' => 'old-run', 'status' => 'active']];
        $source->update(['index_status' => 'cancelled', 'metadata' => $metadata]);
        $this->deleteJson('/api/documents/adoc_delete_test')->assertStatus(409);
        Http::assertNothingSent();
        $metadata['managed_writers']['old']['status'] = 'uncertain';
        $source->update(['metadata' => $metadata]);
        $this->deleteJson('/api/documents/adoc_delete_test')->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_all_historical_writer_identities_are_preserved(): void
    {
        $source = IngestionSource::query()->firstOrFail();
        $metadata = $source->metadata;
        $metadata['temporal'] = ['run_id' => 'latest-run'];
        $metadata['managed_writer_history'] = ['old' => ['workflow_id' => 'older-workflow', 'run_id' => 'older-run']];
        $source->update(['metadata' => $metadata, 'temporal_workflow_id' => 'latest-workflow']);
        $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted();
        $writers = ManagedDocumentDeletion::query()->firstOrFail()->workflow_input['writers'];
        $this->assertCount(2, $writers);
        $this->assertContains(['workflow_id' => 'older-workflow', 'run_id' => 'older-run'], $writers);
    }

    public function test_historical_scope_is_used_and_missing_scope_fails_closed(): void
    {
        $source = IngestionSource::query()->firstOrFail();
        $metadata = $source->metadata;
        $metadata['dataset'] = ['qdrant_collection' => 'historic_collection', 'neo4j_namespace' => 'historic_namespace'];
        $source->update(['metadata' => $metadata]);
        ManagedDocumentOutput::query()->update(['qdrant_collection' => '', 'neo4j_namespace' => null]);
        $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted();
        $this->assertSame('historic_collection', ManagedDocumentDeletion::query()->firstOrFail()->workflow_input['targets'][0]['collection']);
    }

    public function test_missing_historical_scope_is_not_guessed_from_dataset_configuration(): void
    {
        ManagedDocumentOutput::query()->update(['qdrant_collection' => '', 'neo4j_namespace' => null]);
        $this->deleteJson('/api/documents/adoc_delete_test')->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_document_with_no_ingestion_ever_started_has_no_sink_targets(): void
    {
        ManagedDocument::query()->create(['document_id' => 'adoc_never_started', 'dataset_id' => 'delete-test', 'source_type' => 'upload', 'status' => 'pending']);
        $this->deleteJson('/api/documents/adoc_never_started')->assertAccepted();
        $this->assertSame([], ManagedDocumentDeletion::query()->firstOrFail()->workflow_input['targets']);
    }

    public function test_legacy_operation_cannot_dispatch_or_accept_unproven_completion(): void
    {
        $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted();
        $operation = ManagedDocumentDeletion::query()->firstOrFail();
        $operation->update(['attempts' => null]);
        $this->sendReceipt($this->receipt($operation))->assertStatus(409);
        $this->deleteJson('/api/documents/adoc_delete_test')->assertStatus(409);
        $this->assertDatabaseHas('managed_document_deletions', ['status' => 'pending']);
        $this->assertTrue(ManagedDocumentOutput::query()->firstOrFail()->active);
        Http::assertSentCount(1);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_missing_durable_file_allows_corrected_intent_or_authorized_delete(bool $correctedReplacement): void
    {
        $root = storage_path('framework/testing/managed-permanent-failure');
        config()->set('temporal.storage.shared_root', $root);
        config()->set('file_converter.raganything_supported_extensions', ['pdf']);
        $input = fn () => ['force' => true, 'file' => UploadedFile::fake()->createWithContent('replacement.pdf', 'same bytes')];
        try {
            $this->put('/api/documents/adoc_delete_test', $input(), ['Accept' => 'application/json', 'Idempotency-Key' => 'failed-intent'])->assertAccepted();
            $operation = ManagedDocumentDeletion::query()->firstOrFail();
            $this->sendReceipt($this->receipt($operation))->assertOk();
            $source = IngestionSource::query()->where('source_id', $operation->replacement['payload']['source_id'])->firstOrFail();
            File::delete($source->metadata['upload']['local_path']);
            $this->put('/api/documents/adoc_delete_test', $input(), ['Accept' => 'application/json', 'Idempotency-Key' => 'failed-intent'])->assertStatus(422);
            $this->assertDatabaseHas('managed_document_deletions', ['status' => 'abandoned']);
            $this->sendReceipt($this->receipt($operation, 'failed'))->assertOk()->assertJsonPath('status', 'abandoned');
            if ($correctedReplacement) {
                $corrected = fn () => ['force' => true, 'file' => UploadedFile::fake()->createWithContent('corrected.pdf', 'corrected bytes')];
                $this->put('/api/documents/adoc_delete_test', $corrected(), ['Accept' => 'application/json', 'Idempotency-Key' => 'corrected-intent'])->assertAccepted();
                $replacement = ManagedDocumentDeletion::query()->where('status', 'pending')->firstOrFail();
                $this->sendReceipt($this->receipt($replacement))->assertOk();
                $this->put('/api/documents/adoc_delete_test', $corrected(), ['Accept' => 'application/json', 'Idempotency-Key' => 'corrected-intent'])->assertAccepted();
                $this->assertDatabaseHas('managed_document_deletions', ['operation_id' => $replacement->operation_id, 'status' => 'continued']);
            } else {
                $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted();
            }
        } finally {
            File::deleteDirectory($root);
        }
    }

    private function pendingBridge(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), 'delete-managed-document')) {
                if ($this->beforeDeletionAck) {
                    ($this->beforeDeletionAck)($request);
                }

                return $this->loseDeletionAck ? (Http::failedConnection())($request) : Http::response(['workflow_id' => $request->data()['workflow_id'], 'run_id' => $this->deletionRun, 'status' => 'pending']);
            }
            if (str_ends_with($request->url(), '/workflows/ingest')) {
                return $this->loseUploadAck ? (Http::failedConnection())($request) : Http::response(['workflow_id' => $request->data()['workflow_id'], 'run_id' => 'replacement-run']);
            }

            if (str_ends_with($request->url(), '/workflows/status')) {
                return Http::response(['workflow_id' => $request->data()['workflow_id'], 'run_id' => $request->data()['run_id'], 'status' => 'RUNNING']);
            }

            return null;
        });
    }

    private function receipt(ManagedDocumentDeletion $operation, string $status = 'completed'): array
    {
        return ['schema_version' => 1, 'event_id' => 'deletion-event', 'operation_id' => $operation->operation_id,
            'workflow_id' => $operation->workflow_id, 'run_id' => 'delete-run', 'status' => $status,
            'results' => array_map(fn ($target) => ['output_id' => $target['output_id'],
                'qdrant' => ['verified' => true, 'remaining_points' => 0, 'doc_id' => $target['doc_id'], 'collection' => $target['collection'], 'dataset_id' => $operation->dataset_id, 'source_id' => $target['source_id']],
                'neo4j' => ['verified' => true, 'required' => $target['neo4j_namespace'] !== null, 'remaining_contributions' => 0, 'doc_id' => $target['doc_id'], 'namespace' => $target['neo4j_namespace'], 'dataset_id' => $operation->dataset_id],
            ], $operation->workflow_input['targets'])];
    }

    private function sendReceipt(array $receipt)
    {
        $body = json_encode($receipt, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        return $this->call('POST', '/api/internal/pipeline/managed-deletion-events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_HAWKI_TIMESTAMP' => $timestamp,
            'HTTP_X_HAWKI_SIGNATURE' => 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'managed-test-secret'),
        ], $body);
    }
}
