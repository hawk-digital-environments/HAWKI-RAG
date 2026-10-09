<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\Dataset;
use App\Models\IngestionSource;
use App\Models\ManagedDocument;
use App\Models\ManagedDocumentDeletion;
use App\Models\ManagedDocumentOutput;
use App\Services\Authorization\DatasetIngestionAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManagedDocumentDeletionTest extends TestCase
{
    use RefreshDatabase;
    private bool $loseDeletionAck = false;
    private bool $loseUploadAck = false;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('temporal.callbacks.secret', 'managed-test-secret');
        $user = $this->actingAsApiUser();
        $dataset = Dataset::query()->create(['dataset_id' => 'delete-test', 'name' => 'delete-test', 'status' => 'active', 'qdrant_collection' => 'test_collection', 'neo4j_namespace' => 'test_namespace']);
        app(DatasetIngestionAuthorizationService::class)->grantAccess($user, $dataset);
        ManagedDocument::query()->create(['document_id' => 'adoc_delete_test', 'dataset_id' => 'delete-test', 'source_type' => 'upload', 'status' => 'indexed', 'graph_enabled' => true, 'latest_source_id' => 'source-old']);
        IngestionSource::query()->create(['source_id' => 'source-old', 'dataset_id' => 'delete-test', 'source_url' => 'upload://old.pdf', 'index_status' => 'ready', 'metadata' => ['request' => ['managed_document_id' => 'adoc_delete_test']]]);
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

    public function test_failed_sink_evidence_keeps_outputs_active_and_same_retry_identity(): void
    {
        $first = $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted();
        $operation = ManagedDocumentDeletion::query()->firstOrFail();
        $this->sendReceipt($this->receipt($operation, 'failed'))->assertOk();
        $this->assertDatabaseHas('managed_document_outputs', ['active' => true]);
        $this->assertDatabaseHas('managed_documents', ['status' => 'deleting']);
        $this->deleteJson('/api/documents/adoc_delete_test')->assertAccepted()->assertJsonPath('operation.operation_id', $first->json('operation.operation_id'));
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

    public function test_dataset_ingest_grant_is_required(): void
    {
        $this->actingAsApiUser();
        $this->deleteJson('/api/documents/adoc_delete_test')->assertNotFound();
        $this->assertDatabaseCount('managed_document_deletions', 0);
        Http::assertNothingSent();
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
        app(\App\Services\Pipeline\Repositories\IngestionSourceRepository::class)->upsertStarting('source-old', []);
    }

    public function test_update_waits_for_cleanup_and_resumes_one_replacement_task(): void
    {
        $root = storage_path('framework/testing/managed-delete-replacement');
        File::deleteDirectory($root);
        config()->set('temporal.storage.shared_root', $root);
        config()->set('file_converter.raganything_supported_extensions', ['pdf']);
        $input = fn () => ['force' => true, 'file' => UploadedFile::fake()->createWithContent('replacement.pdf', 'same replacement bytes')];
        $headers = ['Accept' => 'application/json', 'Idempotency-Key' => 'update-once'];
        try {
            $this->put('/api/documents/adoc_delete_test', $input(), $headers)->assertAccepted()->assertJsonPath('operation.status', 'pending');
            $this->assertDatabaseCount('pipeline_tasks', 0);
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
            $this->sendReceipt($this->receipt($operation))->assertOk()->assertJsonPath('status', 'continued');
            $this->assertDatabaseHas('managed_documents', ['deleted_at' => null, 'latest_task_id' => $taskId]);
        } finally {
            File::deleteDirectory($root);
        }
    }

    private function pendingBridge(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), 'delete-managed-document')) {
                return $this->loseDeletionAck ? (Http::failedConnection())($request) : Http::response(['workflow_id' => $request->data()['workflow_id'], 'run_id' => 'delete-run', 'status' => 'pending']);
            }
            if (str_ends_with($request->url(), '/workflows/ingest')) {
                return $this->loseUploadAck ? (Http::failedConnection())($request) : Http::response(['workflow_id' => $request->data()['workflow_id'], 'run_id' => 'replacement-run']);
            }
            return null;
        });
    }

    private function receipt(ManagedDocumentDeletion $operation, string $status = 'completed'): array
    {
        return ['schema_version' => 1, 'event_id' => 'deletion-event', 'operation_id' => $operation->operation_id,
            'workflow_id' => $operation->workflow_id, 'run_id' => 'delete-run', 'status' => $status,
            'results' => array_map(fn ($target) => ['output_id' => $target['output_id'],
                'qdrant' => ['verified' => true, 'remaining_points' => 0, 'doc_id' => $target['doc_id'], 'collection' => $target['collection']],
                'neo4j' => ['verified' => true, 'required' => $target['neo4j_namespace'] !== null, 'remaining_contributions' => 0, 'doc_id' => $target['doc_id'], 'namespace' => $target['neo4j_namespace']],
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
