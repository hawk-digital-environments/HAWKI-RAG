<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\IngestedPage;
use App\Models\IngestionSource;
use App\Models\ManagedDocument;
use App\Models\ManagedDocumentOutput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentUnifiedApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsApiUser();
    }

    public function test_create_uploads_and_tracks_managed_document_through_unified_documents_route(): void
    {
        $root = storage_path('framework/testing/unified-documents-create');
        File::deleteDirectory($root);
        config()->set('temporal.storage.shared_root', $root);
        config()->set('file_converter.raganything_supported_extensions', ['pdf']);
        Http::fake([
            '*temporal/workflows/ingest' => Http::response([
                'workflow_id' => 'documents-create-workflow',
                'run_id' => 'documents-create-run-1',
            ]),
        ]);

        $response = $this->post('/api/documents', [
            'dataset_id' => 'documents-create',
            'graph' => 'true',
            'display_name' => 'operator-manual.pdf',
            'source_url' => 'https://operator.example.test/docs/create',
            'source_updated_at' => '2026-07-13T13:35:00Z',
            'metadata_json' => json_encode(['origin' => 'documents']),
            'file' => UploadedFile::fake()->create('manual.pdf', 12, 'application/pdf'),
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'documents-create-1',
        ]);

        $publicDocumentId = $response->json('document.document_id');

        $response
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('operation.type', 'create')
            ->assertJsonPath('document.id', $publicDocumentId)
            ->assertJsonPath('document.document_id', $publicDocumentId)
            ->assertJsonPath('document.dataset_id', 'documents-create')
            ->assertJsonPath('document.graph_enabled', true)
            ->assertJsonPath('document.display_name', 'operator-manual.pdf')
            ->assertJsonMissingPath('document.assistant_document_id');

        $this->assertIsString($publicDocumentId);
        $this->assertStringStartsWith('adoc_', $publicDocumentId);

        $this->assertDatabaseHas('managed_documents', [
            'document_id' => $publicDocumentId,
            'dataset_id' => 'documents-create',
            'display_name' => 'operator-manual.pdf',
        ]);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === config('config.hawki_rag_bridge_url').'/temporal/workflows/ingest'
            && data_get($request->data(), 'workflow_input.dataset_id') === 'documents-create'
            && data_get($request->data(), 'workflow_input.managed_document_id') === $publicDocumentId);

        File::deleteDirectory($root);
    }

    public function test_create_defaults_graph_to_false_when_not_provided_through_unified_documents_route(): void
    {
        $root = storage_path('framework/testing/unified-documents-default-graph');
        File::deleteDirectory($root);
        config()->set('temporal.storage.shared_root', $root);
        config()->set('file_converter.raganything_supported_extensions', ['pdf']);
        Http::fake([
            '*temporal/workflows/ingest' => Http::response([
                'workflow_id' => 'documents-default-graph-workflow',
                'run_id' => 'documents-default-graph-run-1',
            ]),
        ]);

        $response = $this->post('/api/documents', [
            'dataset_id' => 'documents-default-graph',
            'file' => UploadedFile::fake()->create('manual.pdf', 12, 'application/pdf'),
        ], [
            'Accept' => 'application/json',
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('document.graph_enabled', false)
            ->assertJsonMissingPath('document.assistant_document_id');

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === config('config.hawki_rag_bridge_url').'/temporal/workflows/ingest'
            && data_get($request->data(), 'workflow_input.ingestion.graph') === false);

        File::deleteDirectory($root);
    }

    public function test_batch_create_accepts_multiple_files_with_one_dataset_through_unified_documents_route(): void
    {
        $root = storage_path('framework/testing/unified-documents-batch');
        File::deleteDirectory($root);
        config()->set('temporal.storage.shared_root', $root);
        config()->set('file_converter.raganything_supported_extensions', ['pdf']);
        Http::fake([
            '*temporal/workflows/ingest' => Http::sequence()
                ->push([
                    'workflow_id' => 'documents-batch-workflow-1',
                    'run_id' => 'documents-batch-run-1',
                ])
                ->push([
                    'workflow_id' => 'documents-batch-workflow-2',
                    'run_id' => 'documents-batch-run-2',
                ]),
        ]);

        $response = $this->post('/api/documents/batch', [
            'dataset_id' => 'documents-batch',
            'files' => [
                UploadedFile::fake()->create('first.pdf', 12, 'application/pdf'),
                UploadedFile::fake()->create('second.pdf', 12, 'application/pdf'),
            ],
        ], [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'documents-batch-1',
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
            ->assertJsonPath('items.0.result.document.id', $response->json('items.0.result.document.document_id'))
            ->assertJsonPath('items.0.result.document.dataset_id', 'documents-batch')
            ->assertJsonPath('items.0.result.document.graph_enabled', false)
            ->assertJsonPath('items.1.success', true)
            ->assertJsonPath('items.1.result.document.id', $response->json('items.1.result.document.document_id'))
            ->assertJsonPath('items.1.result.document.dataset_id', 'documents-batch')
            ->assertJsonPath('items.1.result.document.graph_enabled', false);

        $this->assertDatabaseCount('managed_documents', 2);
        $this->assertDatabaseHas('managed_documents', [
            'dataset_id' => 'documents-batch',
            'display_name' => 'first.pdf',
            'graph_enabled' => 0,
        ]);
        $this->assertDatabaseHas('managed_documents', [
            'dataset_id' => 'documents-batch',
            'display_name' => 'second.pdf',
            'graph_enabled' => 0,
        ]);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === config('config.hawki_rag_bridge_url').'/temporal/workflows/ingest'
            && data_get($request->data(), 'workflow_input.dataset_id') === 'documents-batch'
            && data_get($request->data(), 'workflow_input.ingestion.graph') === false);

        File::deleteDirectory($root);
    }

    public function test_show_returns_managed_document_payload_when_document_id_uses_adoc_handle(): void
    {
        ManagedDocument::query()->create([
            'document_id' => 'adoc_show_1',
            'dataset_id' => 'documents-show',
            'display_name' => 'policy.pdf',
            'source_type' => 'upload',
            'graph_enabled' => true,
            'status' => ManagedDocument::STATUS_INDEXED,
            'latest_document_version' => 'version-show-1',
            'metadata_json' => ['origin' => 'documents-show'],
        ]);

        ManagedDocumentOutput::query()->create([
            'document_id' => 'adoc_show_1',
            'bridge_document_id' => 'doc-show-1',
            'qdrant_collection' => 'hawki_documents_show',
            'neo4j_namespace' => 'hawki_documents_show',
            'chunk_count' => 7,
            'status' => 'indexed',
            'active' => true,
            'indexed_at' => now(),
        ]);

        $this->getJson('/api/documents/adoc_show_1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('document.id', 'adoc_show_1')
            ->assertJsonPath('document.document_id', 'adoc_show_1')
            ->assertJsonMissingPath('document.assistant_document_id')
            ->assertJsonPath('document.dataset_id', 'documents-show')
            ->assertJsonPath('document.graph_enabled', true)
            ->assertJsonPath('document.outputs.0.bridge_document_id', 'doc-show-1')
            ->assertJsonPath('document.outputs.0.qdrant_collection', 'hawki_documents_show')
            ->assertJsonPath('document.outputs.0.neo4j_namespace', 'hawki_documents_show')
            ->assertJsonPath('document.outputs.0.chunk_count', 7)
            ->assertJsonPath('document.active_output_count', 1)
            ->assertJsonPath('document.active_chunk_count', 7);
    }

    public function test_list_returns_managed_documents_for_dataset_using_public_adoc_handle(): void
    {
        ManagedDocument::query()->create([
            'document_id' => 'adoc_list_1',
            'dataset_id' => 'documents-list',
            'display_name' => 'listable.pdf',
            'source_type' => 'upload',
            'source_url' => 'upload://listable.pdf',
            'graph_enabled' => true,
            'status' => ManagedDocument::STATUS_INDEXED,
            'indexed_at' => Carbon::parse('2026-07-13T13:37:14Z'),
        ]);

        ManagedDocumentOutput::query()->create([
            'document_id' => 'adoc_list_1',
            'bridge_document_id' => 'doc-list-1',
            'qdrant_collection' => 'hawki_documents_list',
            'neo4j_namespace' => 'hawki_documents_list',
            'chunk_count' => 11,
            'status' => 'indexed',
            'active' => true,
            'indexed_at' => now(),
        ]);

        Document::query()->create([
            'id' => (string) Str::uuid(),
            'external_id' => 'doc-list-1',
            'dataset_id' => 'documents-list',
            'collection' => 'hawki_documents_list',
            'source_type' => 'upload',
            'source_url' => 'upload://listable.pdf',
            'original_filename' => 'listable.pdf',
            'storage_path' => '/tmp/documents-list/listable.md',
            'mime_type' => 'text/markdown',
            'file_size' => 123,
            'checksum_sha256' => hash('sha256', 'documents-list'),
            'title' => 'listable',
            'metadata_json' => [
                'source_id' => 'source-list-1',
                'task_id' => 'task-list-1',
                'job_id' => 'ingest-list-1',
                'document_id' => 'doc-list-1',
                'qdrant_collection' => 'hawki_documents_list',
                'neo4j_namespace' => 'hawki_documents_list',
            ],
            'status' => Document::STATUS_COMPLETED,
        ]);

        $this->getJson('/api/documents?dataset_id=documents-list')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.id', 'adoc_list_1')
            ->assertJsonPath('documents.0.document_id', 'adoc_list_1')
            ->assertJsonPath('documents.0.title', 'listable.pdf')
            ->assertJsonPath('documents.0.original_filename', 'listable.pdf')
            ->assertJsonPath('documents.0.qdrant_status', 'indexed')
            ->assertJsonPath('documents.0.neo4j_status', 'indexed')
            ->assertJsonPath('documents.0.qdrant_collection', 'hawki_documents_list');
    }

    public function test_show_returns_enriched_managed_document_detail_from_indexed_backing_document(): void
    {
        $path = storage_path('framework/testing/document-unified/policy.md');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "# Policy\n\nIndexed Markdown content.");

        ManagedDocument::query()->create([
            'document_id' => 'adoc_detail_1',
            'dataset_id' => 'documents-detail',
            'display_name' => 'policy.pdf',
            'source_type' => 'upload',
            'graph_enabled' => true,
            'status' => ManagedDocument::STATUS_PROCESSING,
            'latest_source_id' => 'source-detail-1',
            'latest_task_id' => 'task-detail-1',
            'latest_job_id' => 'ingest-detail-1',
        ]);

        IngestionSource::query()->create([
            'source_id' => 'source-detail-1',
            'source_url' => 'upload://policy.pdf',
            'task_id' => 'task-detail-1',
            'dataset_id' => 'documents-detail',
            'content_hash' => hash('sha256', 'policy-detail'),
            'document_version' => 'version-detail-1',
            'index_status' => IngestionSource::STATUS_READY,
            'metadata' => [],
            'ready_at' => Carbon::parse('2026-07-13T13:37:14Z'),
        ]);

        Document::query()->create([
            'id' => (string) Str::uuid(),
            'external_id' => 'doc-detail-1',
            'dataset_id' => 'documents-detail',
            'collection' => 'hawki_documents_detail',
            'source_type' => 'upload',
            'source_url' => 'upload://policy.pdf',
            'original_filename' => 'policy.pdf',
            'storage_path' => $path,
            'mime_type' => 'text/markdown',
            'file_size' => 321,
            'checksum_sha256' => hash('sha256', 'policy-detail'),
            'title' => 'policy',
            'metadata_json' => [
                'source_id' => 'source-detail-1',
                'task_id' => 'task-detail-1',
                'job_id' => 'ingest-detail-1',
                'document_id' => 'doc-detail-1',
                'qdrant_collection' => 'hawki_documents_detail',
                'neo4j_namespace' => 'hawki_documents_detail',
                'bridge_response' => [
                    'ok' => true,
                    'points' => 7,
                    'summary' => [
                        'graph' => ['enabled' => true],
                        'graph_preview' => [
                            'planned_entities' => 6,
                            'planned_triplets' => 5,
                        ],
                    ],
                ],
            ],
            'status' => Document::STATUS_COMPLETED,
        ]);

        IngestedPage::query()->create([
            'collection' => 'hawki_documents_detail',
            'source_identity_hash' => hash('sha256', 'source-detail-1'),
            'source_identity' => 'policy.pdf',
            'canonical_url_hash' => hash('sha256', 'upload://policy.pdf'),
            'canonical_url' => 'upload://policy.pdf',
            'source_url' => 'upload://policy.pdf',
            'doc_id' => 'doc-detail-1',
            'source_document_id' => 'doc-detail-1',
            'content_hash' => hash('sha256', 'policy-detail'),
            'status' => IngestedPage::STATUS_COMPLETED,
            'source_id' => 'source-detail-1',
            'task_id' => 'task-detail-1',
            'job_id' => 'ingest-detail-1',
            'qdrant_collection' => 'hawki_documents_detail',
            'neo4j_database' => 'hawki_documents_detail',
            'chunks_count' => 7,
            'metadata' => [],
        ]);

        $response = $this->getJson('/api/documents/adoc_detail_1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('document.id', 'adoc_detail_1')
            ->assertJsonPath('document.document_id', 'adoc_detail_1')
            ->assertJsonPath('document.status', ManagedDocument::STATUS_INDEXED)
            ->assertJsonPath('document.title', 'policy')
            ->assertJsonPath('document.original_filename', 'policy.pdf')
            ->assertJsonPath('document.content_type', 'text/markdown')
            ->assertJsonPath('document.qdrant_collection', 'hawki_documents_detail')
            ->assertJsonPath('document.neo4j_namespace', 'hawki_documents_detail')
            ->assertJsonPath('document.qdrant_point_count', 7)
            ->assertJsonPath('document.neo4j_entity_count', 6)
            ->assertJsonPath('document.neo4j_relation_count', 5)
            ->assertJsonPath('document.markdown_preview', "# Policy\n\nIndexed Markdown content.")
            ->assertJsonPath('document.active_output_count', 1)
            ->assertJsonPath('document.active_chunk_count', 7);

        $this->assertTrue(Str::isUuid((string) $response->json('document.indexed_document_id')));
    }

    public function test_show_uses_bridge_chunk_summary_when_upload_has_no_ingested_page_registry_rows(): void
    {
        $bridgeResponse = [
            'ok' => true,
            'points' => 3,
            'summary' => [
                'documents' => [
                    'chunks_per_doc' => [
                        'doc-upload-page-1' => 1,
                        'doc-upload-page-2' => 2,
                    ],
                ],
                'graph' => ['enabled' => false],
                'graph_preview' => [
                    'planned_entities' => 0,
                    'planned_triplets' => 0,
                ],
            ],
        ];

        ManagedDocument::query()->create([
            'document_id' => 'adoc_upload_chunks_1',
            'dataset_id' => 'documents-upload-chunks',
            'display_name' => 'multi-page.pdf',
            'source_type' => 'upload',
            'source_url' => 'upload://multi-page.pdf',
            'graph_enabled' => false,
            'status' => ManagedDocument::STATUS_PROCESSING,
            'latest_source_id' => 'source-upload-chunks-1',
            'latest_task_id' => 'task-upload-chunks-1',
            'latest_job_id' => 'ingest-upload-chunks-1',
        ]);

        IngestionSource::query()->create([
            'source_id' => 'source-upload-chunks-1',
            'source_url' => 'upload://multi-page.pdf',
            'task_id' => 'task-upload-chunks-1',
            'dataset_id' => 'documents-upload-chunks',
            'content_hash' => hash('sha256', 'multi-page.pdf'),
            'document_version' => 'version-upload-chunks-1',
            'index_status' => IngestionSource::STATUS_READY,
            'metadata' => [],
            'ready_at' => Carbon::parse('2026-07-14T08:45:00Z'),
        ]);

        foreach ([
            'doc-upload-page-1' => hash('sha256', 'upload-page-1'),
            'doc-upload-page-2' => hash('sha256', 'upload-page-2'),
        ] as $bridgeDocumentId => $checksum) {
            Document::query()->create([
                'id' => (string) Str::uuid(),
                'external_id' => $bridgeDocumentId,
                'dataset_id' => 'documents-upload-chunks',
                'collection' => 'hawki_documents_upload_chunks',
                'source_type' => 'upload',
                'source_url' => 'upload://multi-page.pdf',
                'original_filename' => 'multi-page.pdf',
                'storage_path' => "/tmp/{$bridgeDocumentId}.md",
                'mime_type' => 'text/markdown',
                'checksum_sha256' => $checksum,
                'title' => $bridgeDocumentId,
                'metadata_json' => [
                    'source_id' => 'source-upload-chunks-1',
                    'task_id' => 'task-upload-chunks-1',
                    'job_id' => 'ingest-upload-chunks-1',
                    'document_id' => $bridgeDocumentId,
                    'qdrant_collection' => 'hawki_documents_upload_chunks',
                    'neo4j_namespace' => 'hawki_documents_upload_chunks',
                    'bridge_response' => $bridgeResponse,
                ],
                'status' => Document::STATUS_COMPLETED,
            ]);
        }

        $this->assertDatabaseCount('ingested_pages', 0);

        $this->getJson('/api/documents/adoc_upload_chunks_1')
            ->assertOk()
            ->assertJsonPath('document.status', ManagedDocument::STATUS_INDEXED)
            ->assertJsonPath('document.qdrant_point_count', 3)
            ->assertJsonPath('document.active_output_count', 2)
            ->assertJsonPath('document.active_chunk_count', 3)
            ->assertJsonFragment([
                'bridge_document_id' => 'doc-upload-page-1',
                'chunk_count' => 1,
            ])
            ->assertJsonFragment([
                'bridge_document_id' => 'doc-upload-page-2',
                'chunk_count' => 2,
            ]);

        $this->assertDatabaseHas('managed_document_outputs', [
            'document_id' => 'adoc_upload_chunks_1',
            'bridge_document_id' => 'doc-upload-page-1',
            'chunk_count' => 1,
        ]);
        $this->assertDatabaseHas('managed_document_outputs', [
            'document_id' => 'adoc_upload_chunks_1',
            'bridge_document_id' => 'doc-upload-page-2',
            'chunk_count' => 2,
        ]);
    }

    public function test_update_skips_when_timestamp_is_not_newer_through_unified_documents_route(): void
    {
        $checksum = hash('sha256', 'documents-skip');
        ManagedDocument::query()->create([
            'document_id' => 'adoc_skip_1',
            'dataset_id' => 'documents-skip',
            'display_name' => 'first-name.pdf',
            'source_type' => 'upload',
            'source_updated_at' => Carbon::parse('2026-07-13T13:35:00Z'),
            'source_checksum_sha256' => $checksum,
            'graph_enabled' => false,
            'status' => ManagedDocument::STATUS_INDEXED,
        ]);

        Http::fake();

        $this->put('/api/documents/adoc_skip_1', [
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
            ->assertJsonPath('operation.status', ManagedDocument::STATUS_SKIPPED_UNCHANGED)
            ->assertJsonPath('document.id', 'adoc_skip_1')
            ->assertJsonPath('document.document_id', 'adoc_skip_1')
            ->assertJsonPath('document.status', ManagedDocument::STATUS_INDEXED)
            ->assertJsonPath('document.display_name', 'renamed-without-reingest.pdf');

        Http::assertNothingSent();
    }

    public function test_delete_fans_out_to_bridge_and_soft_deletes_outputs_through_unified_documents_route(): void
    {
        ManagedDocument::query()->create([
            'document_id' => 'adoc_delete_1',
            'dataset_id' => 'documents-delete',
            'display_name' => 'delete-me.pdf',
            'source_type' => 'upload',
            'graph_enabled' => true,
            'status' => ManagedDocument::STATUS_INDEXED,
        ]);

        ManagedDocumentOutput::query()->create([
            'document_id' => 'adoc_delete_1',
            'bridge_document_id' => 'doc-delete-1',
            'qdrant_collection' => 'hawki_documents_delete',
            'neo4j_namespace' => 'hawki_documents_delete',
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
                    'collection' => 'hawki_documents_delete',
                    'deleted_points' => 83,
                    'result' => [
                        'status' => 'completed',
                        'deleted' => 83,
                    ],
                ],
                'neo4j' => [
                    'doc_id' => 'doc-delete-1',
                    'namespace' => 'hawki_documents_delete',
                    'relationships_deleted' => 214,
                    'entities_deleted' => 97,
                ],
            ]),
        ]);

        $this->deleteJson('/api/documents/adoc_delete_1', [], [
            'Idempotency-Key' => 'documents-delete-1',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('operation.type', 'delete')
            ->assertJsonPath('operation.status', 'completed')
            ->assertJsonPath('document.id', 'adoc_delete_1')
            ->assertJsonPath('document.document_id', 'adoc_delete_1')
            ->assertJsonPath('document.status', ManagedDocument::STATUS_DELETED)
            ->assertJsonMissingPath('document.assistant_document_id')
            ->assertJsonPath('deletion.bridge_documents_deleted', 1)
            ->assertJsonPath('deletion.qdrant.0.bridge_document_id', 'doc-delete-1')
            ->assertJsonPath('deletion.qdrant.0.collection', 'hawki_documents_delete')
            ->assertJsonPath('deletion.qdrant.0.deleted_points', 83)
            ->assertJsonPath('deletion.qdrant.0.result.deleted_points', 83)
            ->assertJsonPath('deletion.neo4j.0.bridge_document_id', 'doc-delete-1')
            ->assertJsonPath('deletion.neo4j.0.namespace', 'hawki_documents_delete')
            ->assertJsonPath('deletion.neo4j.0.relationships_deleted', 214)
            ->assertJsonPath('deletion.neo4j.0.entities_deleted', 97);

        $this->assertDatabaseHas('managed_documents', [
            'document_id' => 'adoc_delete_1',
            'status' => ManagedDocument::STATUS_DELETED,
        ]);
        $this->assertDatabaseHas('managed_document_outputs', [
            'document_id' => 'adoc_delete_1',
            'bridge_document_id' => 'doc-delete-1',
            'active' => 0,
            'status' => 'deleted',
        ]);

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && $request->url() === config('config.hawki_rag_bridge_url').'/documents/doc-delete-1?collection=hawki_documents_delete&neo4j_namespace=hawki_documents_delete'
            && $request->hasHeader('Idempotency-Key', ['documents-delete-1']));
    }

    public function test_delete_backfills_missing_output_scope_from_dataset_before_bridge_delete_through_unified_documents_route(): void
    {
        \App\Models\Dataset::query()->create([
            'dataset_id' => 'documents-delete-backfill',
            'name' => 'documents-delete-backfill',
            'status' => 'active',
            'qdrant_collection' => 'hawki_documents_delete_backfill',
            'neo4j_namespace' => 'hawki_documents_delete_backfill',
            'created_at' => Carbon::parse('2026-07-09T10:00:00Z'),
        ]);

        ManagedDocument::query()->create([
            'document_id' => 'adoc_delete_backfill_1',
            'dataset_id' => 'documents-delete-backfill',
            'display_name' => 'delete-backfill.pdf',
            'source_type' => 'upload',
            'graph_enabled' => false,
            'status' => ManagedDocument::STATUS_INDEXED,
        ]);

        ManagedDocumentOutput::query()->create([
            'document_id' => 'adoc_delete_backfill_1',
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
                    'collection' => 'hawki_documents_delete_backfill',
                    'deleted_points' => 2,
                    'result' => [
                        'status' => 'completed',
                    ],
                ],
                'neo4j' => [
                    'doc_id' => 'doc-delete-backfill-1',
                    'namespace' => 'hawki_documents_delete_backfill',
                    'relationships_deleted' => 0,
                    'entities_deleted' => 0,
                ],
            ]),
        ]);

        $this->deleteJson('/api/documents/adoc_delete_backfill_1')
            ->assertOk()
            ->assertJsonPath('document.id', 'adoc_delete_backfill_1')
            ->assertJsonPath('deletion.qdrant.0.collection', 'hawki_documents_delete_backfill')
            ->assertJsonPath('deletion.neo4j.0.namespace', 'hawki_documents_delete_backfill');

        $this->assertDatabaseHas('managed_document_outputs', [
            'document_id' => 'adoc_delete_backfill_1',
            'bridge_document_id' => 'doc-delete-backfill-1',
            'qdrant_collection' => 'hawki_documents_delete_backfill',
            'neo4j_namespace' => 'hawki_documents_delete_backfill',
            'active' => 0,
        ]);

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && $request->url() === config('config.hawki_rag_bridge_url').'/documents/doc-delete-backfill-1?collection=hawki_documents_delete_backfill&neo4j_namespace=hawki_documents_delete_backfill');
    }
}
