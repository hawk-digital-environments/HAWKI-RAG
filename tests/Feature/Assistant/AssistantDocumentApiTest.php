<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Models\AssistantDocument;
use App\Models\AssistantDocumentOutput;
use App\Models\Document;
use App\Models\IngestedPage;
use App\Models\IngestionSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssistantDocumentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsApiUser();
    }

    public function test_create_uploads_and_tracks_assistant_document(): void
    {
        $root = storage_path('framework/testing/assistant-documents-create');
        File::deleteDirectory($root);
        config()->set('temporal.storage.shared_root', $root);
        config()->set('file_converter.raganything_supported_extensions', ['pdf']);
        Http::fake([
            '*temporal/workflows/ingest' => Http::response([
                'workflow_id' => 'assistant-create-workflow',
                'run_id' => 'assistant-create-run-1',
            ]),
        ]);

        $response = $this->post('/api/assistant/documents', [
            'dataset_id' => 'assistant-create',
            'graph' => 'true',
            'display_name' => 'assistant-manual.pdf',
            'source_url' => 'https://assistant.example.test/docs/create',
            'source_updated_at' => '2026-07-08T13:35:00Z',
            'metadata_json' => json_encode(['origin' => 'assistant']),
            'file' => UploadedFile::fake()->create('assistant.pdf', 12, 'application/pdf'),
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'assistant-create-1',
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('operation.type', 'create')
            ->assertJsonPath('operation.status', 'accepted')
            ->assertJsonPath('document.dataset_id', 'assistant-create')
            ->assertJsonPath('document.status', AssistantDocument::STATUS_PROCESSING)
            ->assertJsonPath('document.graph_enabled', true)
            ->assertJsonPath('document.display_name', 'assistant-manual.pdf');

        $assistantDocumentId = $response->json('document.assistant_document_id');
        $taskId = $response->json('pipeline.task_id');
        $jobId = $response->json('pipeline.job_id');
        $sourceId = $response->json('pipeline.source_id');

        $this->assertIsString($assistantDocumentId);
        $this->assertIsString($taskId);
        $this->assertIsString($jobId);
        $this->assertIsString($sourceId);

        $this->assertDatabaseHas('assistant_documents', [
            'assistant_document_id' => $assistantDocumentId,
            'dataset_id' => 'assistant-create',
            'display_name' => 'assistant-manual.pdf',
            'graph_enabled' => 1,
            'status' => AssistantDocument::STATUS_PROCESSING,
            'source_url' => 'https://assistant.example.test/docs/create',
            'latest_source_id' => $sourceId,
            'latest_task_id' => $taskId,
            'latest_job_id' => $jobId,
        ]);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === config('config.hawki_rag_bridge_url').'/temporal/workflows/ingest'
            && data_get($request->data(), 'workflow_input.dataset_id') === 'assistant-create'
            && data_get($request->data(), 'workflow_input.assistant_document_id') === $assistantDocumentId
            && data_get($request->data(), 'workflow_input.ingestion.graph') === true);

        File::deleteDirectory($root);
    }

    public function test_create_defaults_graph_to_false_when_not_provided(): void
    {
        $root = storage_path('framework/testing/assistant-documents-default-graph');
        File::deleteDirectory($root);
        config()->set('temporal.storage.shared_root', $root);
        config()->set('file_converter.raganything_supported_extensions', ['pdf']);
        Http::fake([
            '*temporal/workflows/ingest' => Http::response([
                'workflow_id' => 'assistant-default-graph-workflow',
                'run_id' => 'assistant-default-graph-run-1',
            ]),
        ]);

        $response = $this->post('/api/assistant/documents', [
            'dataset_id' => 'assistant-default-graph',
            'file' => UploadedFile::fake()->create('assistant.pdf', 12, 'application/pdf'),
        ], [
            'Accept' => 'application/json',
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('document.graph_enabled', false);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === config('config.hawki_rag_bridge_url').'/temporal/workflows/ingest'
            && data_get($request->data(), 'workflow_input.ingestion.graph') === false);

        File::deleteDirectory($root);
    }

    public function test_batch_create_accepts_multiple_files_with_one_dataset(): void
    {
        $root = storage_path('framework/testing/assistant-documents-batch');
        File::deleteDirectory($root);
        config()->set('temporal.storage.shared_root', $root);
        config()->set('file_converter.raganything_supported_extensions', ['pdf']);
        Http::fake([
            '*temporal/workflows/ingest' => Http::sequence()
                ->push([
                    'workflow_id' => 'assistant-batch-workflow-1',
                    'run_id' => 'assistant-batch-run-1',
                ])
                ->push([
                    'workflow_id' => 'assistant-batch-workflow-2',
                    'run_id' => 'assistant-batch-run-2',
                ]),
        ]);

        $response = $this->post('/api/assistant/documents/batch', [
            'dataset_id' => 'assistant-batch',
            'files' => [
                UploadedFile::fake()->create('first.pdf', 12, 'application/pdf'),
                UploadedFile::fake()->create('second.pdf', 12, 'application/pdf'),
            ],
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'assistant-batch-1',
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('operation.type', 'batch_create')
            ->assertJsonPath('operation.status', 'accepted')
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.accepted', 2)
            ->assertJsonPath('summary.failed', 0)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.success', true)
            ->assertJsonPath('items.0.result.document.dataset_id', 'assistant-batch')
            ->assertJsonPath('items.0.result.document.graph_enabled', false)
            ->assertJsonPath('items.1.success', true)
            ->assertJsonPath('items.1.result.document.dataset_id', 'assistant-batch')
            ->assertJsonPath('items.1.result.document.graph_enabled', false);

        $this->assertDatabaseCount('assistant_documents', 2);
        $this->assertDatabaseHas('assistant_documents', [
            'dataset_id' => 'assistant-batch',
            'display_name' => 'first.pdf',
            'graph_enabled' => 0,
        ]);
        $this->assertDatabaseHas('assistant_documents', [
            'dataset_id' => 'assistant-batch',
            'display_name' => 'second.pdf',
            'graph_enabled' => 0,
        ]);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === config('config.hawki_rag_bridge_url').'/temporal/workflows/ingest'
            && data_get($request->data(), 'workflow_input.dataset_id') === 'assistant-batch'
            && data_get($request->data(), 'workflow_input.ingestion.graph') === false);

        File::deleteDirectory($root);
    }

    public function test_show_syncs_outputs_from_ingestion_metadata(): void
    {
        $assistantDocument = AssistantDocument::query()->create([
            'assistant_document_id' => 'adoc_sync_1',
            'dataset_id' => 'assistant-sync',
            'display_name' => 'policy.pdf',
            'source_type' => 'upload',
            'graph_enabled' => true,
            'status' => AssistantDocument::STATUS_PROCESSING,
            'latest_source_id' => 'source-ready-1',
            'latest_task_id' => 'task-ready-1',
            'latest_job_id' => 'ingest-ready-1',
        ]);

        IngestionSource::query()->create([
            'source_id' => 'source-ready-1',
            'source_url' => 'upload://policy.pdf',
            'task_id' => 'task-ready-1',
            'dataset_id' => 'assistant-sync',
            'content_hash' => hash('sha256', 'policy-ready'),
            'document_version' => 'version-ready-1',
            'index_status' => IngestionSource::STATUS_READY,
            'metadata' => [],
            'ready_at' => Carbon::parse('2026-07-08T13:37:14Z'),
        ]);

        Document::query()->create([
            'id' => (string) Str::uuid(),
            'external_id' => 'doc-ready-1',
            'dataset_id' => 'assistant-sync',
            'collection' => 'hawki_assistant_sync',
            'source_type' => 'upload',
            'source_url' => 'upload://policy.pdf',
            'original_filename' => 'policy.pdf',
            'storage_path' => '/tmp/assistant-sync/policy.md',
            'mime_type' => 'text/markdown',
            'file_size' => 123,
            'checksum_sha256' => hash('sha256', 'policy-ready'),
            'title' => 'policy',
            'metadata_json' => [
                'source_id' => 'source-ready-1',
                'task_id' => 'task-ready-1',
                'job_id' => 'ingest-ready-1',
                'document_id' => 'doc-ready-1',
                'qdrant_collection' => 'hawki_assistant_sync',
                'neo4j_namespace' => 'hawki_assistant_sync',
            ],
            'status' => Document::STATUS_COMPLETED,
        ]);

        IngestedPage::query()->create([
            'collection' => 'hawki_assistant_sync',
            'source_identity_hash' => hash('sha256', 'source-ready-1'),
            'source_identity' => 'policy.pdf',
            'canonical_url_hash' => hash('sha256', 'upload://policy.pdf'),
            'canonical_url' => 'upload://policy.pdf',
            'source_url' => 'upload://policy.pdf',
            'doc_id' => 'doc-ready-1',
            'source_document_id' => 'doc-ready-1',
            'content_hash' => hash('sha256', 'policy-ready'),
            'status' => IngestedPage::STATUS_COMPLETED,
            'source_id' => 'source-ready-1',
            'task_id' => 'task-ready-1',
            'job_id' => 'ingest-ready-1',
            'qdrant_collection' => 'hawki_assistant_sync',
            'neo4j_database' => 'hawki_assistant_sync',
            'chunks_count' => 7,
            'metadata' => [],
        ]);

        $this->getJson("/api/assistant/documents/{$assistantDocument->assistant_document_id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('document.status', AssistantDocument::STATUS_INDEXED)
            ->assertJsonPath('document.latest_document_version', 'version-ready-1')
            ->assertJsonPath('document.source_checksum_sha256', hash('sha256', 'policy-ready'))
            ->assertJsonPath('document.outputs.0.bridge_document_id', 'doc-ready-1')
            ->assertJsonPath('document.outputs.0.qdrant_collection', 'hawki_assistant_sync')
            ->assertJsonPath('document.outputs.0.neo4j_namespace', 'hawki_assistant_sync')
            ->assertJsonPath('document.outputs.0.chunk_count', 7)
            ->assertJsonPath('document.active_output_count', 1)
            ->assertJsonPath('document.active_chunk_count', 7);

        $this->assertDatabaseHas('assistant_document_outputs', [
            'assistant_document_id' => $assistantDocument->assistant_document_id,
            'bridge_document_id' => 'doc-ready-1',
            'source_id' => 'source-ready-1',
            'task_id' => 'task-ready-1',
            'job_id' => 'ingest-ready-1',
            'chunk_count' => 7,
            'active' => 1,
        ]);
    }

    public function test_update_skips_when_timestamp_is_not_newer(): void
    {
        $checksum = hash('sha256', 'assistant-skip');
        AssistantDocument::query()->create([
            'assistant_document_id' => 'adoc_skip_1',
            'dataset_id' => 'assistant-skip',
            'display_name' => 'first-name.pdf',
            'source_type' => 'upload',
            'source_updated_at' => Carbon::parse('2026-07-08T13:35:00Z'),
            'source_checksum_sha256' => $checksum,
            'graph_enabled' => false,
            'status' => AssistantDocument::STATUS_INDEXED,
        ]);

        Http::fake();

        $this->put('/api/assistant/documents/adoc_skip_1', [
            'file' => UploadedFile::fake()->create('replacement.pdf', 12, 'application/pdf'),
            'source_updated_at' => '2026-07-08T13:34:00Z',
            'source_checksum_sha256' => $checksum,
            'display_name' => 'renamed-without-reingest.pdf',
        ], [
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('operation.type', 'update')
            ->assertJsonPath('operation.status', AssistantDocument::STATUS_SKIPPED_UNCHANGED)
            ->assertJsonPath('operation.reason', 'incoming source_updated_at is not newer than the stored document')
            ->assertJsonPath('document.status', AssistantDocument::STATUS_INDEXED)
            ->assertJsonPath('document.display_name', 'renamed-without-reingest.pdf');

        Http::assertNothingSent();
    }

    public function test_delete_fans_out_to_bridge_and_soft_deletes_outputs(): void
    {
        AssistantDocument::query()->create([
            'assistant_document_id' => 'adoc_delete_1',
            'dataset_id' => 'assistant-delete',
            'display_name' => 'delete-me.pdf',
            'source_type' => 'upload',
            'graph_enabled' => true,
            'status' => AssistantDocument::STATUS_INDEXED,
        ]);

        AssistantDocumentOutput::query()->create([
            'assistant_document_id' => 'adoc_delete_1',
            'bridge_document_id' => 'doc-delete-1',
            'qdrant_collection' => 'hawki_assistant_delete',
            'neo4j_namespace' => 'hawki_assistant_delete',
            'source_id' => 'source-delete-1',
            'task_id' => 'task-delete-1',
            'job_id' => 'ingest-delete-1',
            'content_hash' => hash('sha256', 'delete-me'),
            'chunk_count' => 83,
            'status' => 'indexed',
            'active' => true,
        ]);

        Http::fake([
            '*documents/doc-delete-1*' => Http::response([
                'ok' => true,
                'doc_id' => 'doc-delete-1',
                'qdrant' => [
                    'doc_id' => 'doc-delete-1',
                    'collection' => 'hawki_assistant_delete',
                    'deleted_points' => 83,
                    'result' => [
                        'status' => 'completed',
                        'deleted' => 83,
                    ],
                ],
                'neo4j' => [
                    'doc_id' => 'doc-delete-1',
                    'namespace' => 'hawki_assistant_delete',
                    'relationships_deleted' => 214,
                    'entities_deleted' => 97,
                ],
            ]),
        ]);

        $this->deleteJson('/api/assistant/documents/adoc_delete_1', [], [
            'Idempotency-Key' => 'assistant-delete-1',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('operation.type', 'delete')
            ->assertJsonPath('operation.status', 'completed')
            ->assertJsonPath('document.status', AssistantDocument::STATUS_DELETED)
            ->assertJsonPath('deletion.bridge_documents_deleted', 1)
            ->assertJsonPath('deletion.qdrant.0.bridge_document_id', 'doc-delete-1')
            ->assertJsonPath('deletion.qdrant.0.collection', 'hawki_assistant_delete')
            ->assertJsonPath('deletion.qdrant.0.deleted_points', 83)
            ->assertJsonPath('deletion.qdrant.0.result.deleted_points', 83)
            ->assertJsonPath('deletion.neo4j.0.bridge_document_id', 'doc-delete-1')
            ->assertJsonPath('deletion.neo4j.0.namespace', 'hawki_assistant_delete')
            ->assertJsonPath('deletion.neo4j.0.relationships_deleted', 214)
            ->assertJsonPath('deletion.neo4j.0.entities_deleted', 97);

        $this->assertDatabaseHas('assistant_documents', [
            'assistant_document_id' => 'adoc_delete_1',
            'status' => AssistantDocument::STATUS_DELETED,
        ]);
        $this->assertDatabaseHas('assistant_document_outputs', [
            'assistant_document_id' => 'adoc_delete_1',
            'bridge_document_id' => 'doc-delete-1',
            'active' => 0,
            'status' => 'deleted',
        ]);

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && $request->url() === config('config.hawki_rag_bridge_url').'/documents/doc-delete-1?collection=hawki_assistant_delete&neo4j_namespace=hawki_assistant_delete'
            && $request->hasHeader('Idempotency-Key', ['assistant-delete-1']));
    }

    public function test_delete_backfills_missing_output_scope_from_dataset_before_bridge_delete(): void
    {
        \App\Models\Dataset::query()->create([
            'dataset_id' => 'assistant-delete-backfill',
            'name' => 'assistant-delete-backfill',
            'status' => 'active',
            'qdrant_collection' => 'hawki_assistant_delete_backfill',
            'neo4j_namespace' => 'hawki_assistant_delete_backfill',
            'created_at' => Carbon::parse('2026-07-09T10:00:00Z'),
        ]);

        AssistantDocument::query()->create([
            'assistant_document_id' => 'adoc_delete_backfill_1',
            'dataset_id' => 'assistant-delete-backfill',
            'display_name' => 'delete-backfill.pdf',
            'source_type' => 'upload',
            'graph_enabled' => false,
            'status' => AssistantDocument::STATUS_INDEXED,
        ]);

        AssistantDocumentOutput::query()->create([
            'assistant_document_id' => 'adoc_delete_backfill_1',
            'bridge_document_id' => 'doc-delete-backfill-1',
            'qdrant_collection' => '',
            'neo4j_namespace' => null,
            'source_id' => 'source-delete-backfill-1',
            'task_id' => 'task-delete-backfill-1',
            'job_id' => 'ingest-delete-backfill-1',
            'content_hash' => hash('sha256', 'delete-backfill'),
            'chunk_count' => 2,
            'status' => 'indexed',
            'active' => true,
        ]);

        Http::fake([
            '*documents/doc-delete-backfill-1*' => Http::response([
                'ok' => true,
                'doc_id' => 'doc-delete-backfill-1',
                'qdrant' => [
                    'doc_id' => 'doc-delete-backfill-1',
                    'collection' => 'hawki_assistant_delete_backfill',
                    'deleted_points' => 2,
                    'result' => [
                        'status' => 'completed',
                    ],
                ],
                'neo4j' => [
                    'doc_id' => 'doc-delete-backfill-1',
                    'namespace' => 'hawki_assistant_delete_backfill',
                    'relationships_deleted' => 0,
                    'entities_deleted' => 0,
                ],
            ]),
        ]);

        $this->deleteJson('/api/assistant/documents/adoc_delete_backfill_1')
            ->assertOk()
            ->assertJsonPath('deletion.qdrant.0.collection', 'hawki_assistant_delete_backfill')
            ->assertJsonPath('deletion.neo4j.0.namespace', 'hawki_assistant_delete_backfill');

        $this->assertDatabaseHas('assistant_document_outputs', [
            'assistant_document_id' => 'adoc_delete_backfill_1',
            'bridge_document_id' => 'doc-delete-backfill-1',
            'qdrant_collection' => 'hawki_assistant_delete_backfill',
            'neo4j_namespace' => 'hawki_assistant_delete_backfill',
            'active' => 0,
        ]);

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && $request->url() === config('config.hawki_rag_bridge_url').'/documents/doc-delete-backfill-1?collection=hawki_assistant_delete_backfill&neo4j_namespace=hawki_assistant_delete_backfill');
    }
}
