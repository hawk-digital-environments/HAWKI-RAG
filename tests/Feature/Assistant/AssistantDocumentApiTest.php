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

    public function test_legacy_create_route_adds_assistant_document_id_to_unified_payload(): void
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
            'source_updated_at' => '2026-07-13T13:35:00Z',
            'metadata_json' => json_encode(['origin' => 'assistant']),
            'file' => UploadedFile::fake()->create('assistant.pdf', 12, 'application/pdf'),
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'assistant-create-1',
        ]);

        $assistantDocumentId = $response->json('document.assistant_document_id');

        $response
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('operation.type', 'create')
            ->assertJsonPath('document.id', $assistantDocumentId)
            ->assertJsonPath('document.document_id', $assistantDocumentId)
            ->assertJsonPath('document.dataset_id', 'assistant-create')
            ->assertJsonPath('document.graph_enabled', true)
            ->assertJsonPath('document.display_name', 'assistant-manual.pdf');

        $this->assertIsString($assistantDocumentId);
        $this->assertDatabaseHas('assistant_documents', [
            'assistant_document_id' => $assistantDocumentId,
            'dataset_id' => 'assistant-create',
        ]);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === config('config.hawki_rag_bridge_url').'/temporal/workflows/ingest'
            && data_get($request->data(), 'workflow_input.assistant_document_id') === $assistantDocumentId);

        File::deleteDirectory($root);
    }

    public function test_legacy_batch_route_adds_assistant_document_id_to_each_item(): void
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
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('items.0.result.document.id', $response->json('items.0.result.document.assistant_document_id'))
            ->assertJsonPath('items.1.result.document.id', $response->json('items.1.result.document.assistant_document_id'));

        $this->assertDatabaseCount('assistant_documents', 2);
        File::deleteDirectory($root);
    }

    public function test_legacy_show_route_adds_assistant_document_id_to_managed_document_payload(): void
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
            'ready_at' => Carbon::parse('2026-07-13T13:37:14Z'),
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
            ->assertJsonPath('document.assistant_document_id', 'adoc_sync_1')
            ->assertJsonPath('document.id', 'adoc_sync_1')
            ->assertJsonPath('document.document_id', 'adoc_sync_1')
            ->assertJsonPath('document.status', AssistantDocument::STATUS_INDEXED)
            ->assertJsonPath('document.outputs.0.bridge_document_id', 'doc-ready-1')
            ->assertJsonPath('document.active_chunk_count', 7);
    }

    public function test_legacy_update_route_preserves_assistant_document_id_when_managed_update_skips(): void
    {
        $checksum = hash('sha256', 'assistant-skip');
        AssistantDocument::query()->create([
            'assistant_document_id' => 'adoc_skip_1',
            'dataset_id' => 'assistant-skip',
            'display_name' => 'first-name.pdf',
            'source_type' => 'upload',
            'source_updated_at' => Carbon::parse('2026-07-13T13:35:00Z'),
            'source_checksum_sha256' => $checksum,
            'graph_enabled' => false,
            'status' => AssistantDocument::STATUS_INDEXED,
        ]);

        Http::fake();

        $this->put('/api/assistant/documents/adoc_skip_1', [
            'file' => UploadedFile::fake()->create('replacement.pdf', 12, 'application/pdf'),
            'source_updated_at' => '2026-07-13T13:34:00Z',
            'source_checksum_sha256' => $checksum,
            'display_name' => 'renamed-without-reingest.pdf',
        ], [
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('operation.type', 'update')
            ->assertJsonPath('operation.status', AssistantDocument::STATUS_SKIPPED_UNCHANGED)
            ->assertJsonPath('document.assistant_document_id', 'adoc_skip_1')
            ->assertJsonPath('document.id', 'adoc_skip_1')
            ->assertJsonPath('document.display_name', 'renamed-without-reingest.pdf');

        Http::assertNothingSent();
    }

    public function test_legacy_delete_route_preserves_assistant_document_id_when_managed_delete_completes(): void
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
            ->assertJsonPath('document.assistant_document_id', 'adoc_delete_1')
            ->assertJsonPath('document.id', 'adoc_delete_1')
            ->assertJsonPath('document.status', AssistantDocument::STATUS_DELETED)
            ->assertJsonPath('deletion.bridge_documents_deleted', 1);
    }
}
