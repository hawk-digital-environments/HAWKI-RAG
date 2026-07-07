<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Document;
use App\Models\PipelineTask;
use App\Models\SpecV2\Corpus;
use App\Models\SpecV2\GroupMember;
use App\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpecV2DomainApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_v2_domain_endpoints_create_and_expose_spec_terms(): void
    {
        $this->actingAsApplication([
            'id' => 'bootstrap-app',
            'tenant_id' => 'bootstrap',
            'permissions' => ['reads-federated'],
        ]);

        $this->postJson('/api/tenants', [
            'id' => 'uni-hawk',
            'name' => 'University Hawk',
            'metadata' => ['kind' => 'university'],
        ])->assertCreated()
            ->assertJsonPath('id', 'uni-hawk')
            ->assertJsonPath('metadata.kind', 'university');

        $application = $this->postJson('/api/applications', [
            'id' => 'hawki-web',
            'tenant_id' => 'uni-hawk',
            'name' => 'HAWKI Web',
            'permissions' => ['reads-all-apps', 'reads'],
        ])->assertCreated()
            ->assertJsonPath('id', 'hawki-web')
            ->assertJsonPath('tenantId', 'uni-hawk')
            ->assertJsonPath('permissions.0', 'reads-all-apps')
            ->assertJsonPath('tokenType', 'Bearer');
        $token = $application->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/heaps', [
            'id' => 'heap-design',
            'name' => 'Design Heap',
            'owner_application_id' => 'hawki-web',
            'visibility' => 'hidden',
            'metadata' => ['course' => 'design'],
        ])->assertCreated()
            ->assertJsonPath('id', 'heap-design')
            ->assertJsonPath('tenantId', 'uni-hawk')
            ->assertJsonPath('ownerApp', 'hawki-web')
            ->assertJsonPath('visibility', 'hidden')
            ->assertJsonPath('metadata.course', 'design');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/groups', [
            'id' => 'design_students',
            'name' => 'Design Students',
            'owner_application_id' => 'hawki-web',
        ])->assertCreated()
            ->assertJsonPath('id', 'hawki-web:design_students')
            ->assertJsonPath('ownerApp', 'hawki-web');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/auth/groups/hawki-web:design_students/users', [
                'users' => ['alice@hawk.de', 'bob@hawk.de'],
            ])->assertOk()
            ->assertJsonPath('data.0', 'alice@hawk.de')
            ->assertJsonPath('pagination.total', 2);

        $member = GroupMember::query()
            ->where('group_id', 'hawki-web:design_students')
            ->where('user_identifier', 'alice@hawk.de')
            ->first();

        $this->assertNotNull($member);
        $this->assertNotNull($member->internal_user_id);
        $this->assertDatabaseHas('user_identities', [
            'tenant_id' => 'uni-hawk',
            'application_id' => 'hawki-web',
            'external_user_id' => 'alice@hawk.de',
            'internal_user_id' => $member->internal_user_id,
        ]);

        Corpus::query()->create([
            'id' => hash('sha256', 'design corpus'),
            'content' => 'Design requirements and portfolio expectations.',
            'reference_count' => 1,
            'metadata_json' => ['seeded' => true],
        ]);

        Document::query()->create([
            'dataset_id' => 'heap-design',
            'corpus_id' => hash('sha256', 'design corpus'),
            'collection' => 'hawki_heap_design',
            'source_type' => Document::SOURCE_MANUAL,
            'source_url' => 'spec://design',
            'storage_path' => storage_path('framework/testing/specv2/design.md'),
            'checksum_sha256' => hash('sha256', 'design corpus'),
            'status' => Document::STATUS_COMPLETED,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/corpora')
            ->assertOk()
            ->assertJsonPath('data.0.id', hash('sha256', 'design corpus'))
            ->assertJsonPath('data.0.referenceCount', 1);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/corpora/'.hash('sha256', 'design corpus'))
            ->assertOk()
            ->assertJsonPath('id', hash('sha256', 'design corpus'))
            ->assertJsonPath('documentCount', 1);
    }

    public function test_canonical_v2_document_routes_create_update_and_delete_documents(): void
    {
        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');
        Http::fake([
            'http://bridge.test/ingest' => Http::response(['ok' => true], 200),
            'http://bridge.test/documents/*' => Http::response(['ok' => true], 200),
        ]);

        ['token' => $token] = $this->issueApplicationToken([
            'id' => 'rawki-default',
            'tenant_id' => 'default',
        ]);

        Dataset::query()->create([
            'dataset_id' => 'heap-spec-docs',
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
            'name' => 'Heap Spec Docs',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => ['source' => 'api'],
            'qdrant_collection' => 'heap_spec_docs',
            'neo4j_namespace' => 'heap_spec_docs',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $auth = ['Authorization' => 'Bearer '.$token];

        $this->withHeaders($auth)
            ->postJson('/api/heaps/heap-spec-docs/documents', [
                'document_id' => 'doc-spec-1',
                'content' => 'Initial design guidance.',
                'metadata' => ['course' => 'design'],
                'source_url' => 'https://example.test/spec-doc-1',
                'title' => 'Spec Document',
            ])->assertCreated()
            ->assertJsonPath('documentId', 'doc-spec-1')
            ->assertJsonPath('heapId', 'heap-spec-docs')
            ->assertJsonPath('metadata.course', 'design')
            ->assertJsonPath('isDuplicate', false);

        $this->withHeaders($auth)
            ->getJson('/api/documents/doc-spec-1')
            ->assertOk()
            ->assertJsonPath('documentId', 'doc-spec-1')
            ->assertJsonPath('heapId', 'heap-spec-docs')
            ->assertJsonPath('metadata.course', 'design');

        $document = Document::query()->findOrFail('doc-spec-1');
        $this->assertSame('design', $document->metadata_json['course']);
        $this->assertSame(['source' => 'api'], $document->heap->metadata_json ?? []);
        $this->assertArrayHasKey('__rawki', $document->metadata_json);
        $this->assertSame([
            'schema' => 1,
            'heap' => 'heap-spec-docs',
            'documentId' => 'doc-spec-1',
        ], $document->metadata_json['__rawki']['audit']);

        $this->withHeaders($auth)
            ->getJson('/api/heaps/heap-spec-docs/documents')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.documentId', 'doc-spec-1');

        $this->withHeaders($auth)
            ->putJson('/api/documents/doc-spec-1', [
                'content' => 'Updated design guidance.',
                'metadata' => ['course' => 'ux'],
                'title' => 'Updated Spec Document',
            ])->assertOk()
            ->assertJsonPath('documentId', 'doc-spec-1')
            ->assertJsonPath('metadata.course', 'ux')
            ->assertJsonPath('title', 'Updated Spec Document');

        $document->refresh();
        $this->assertSame('ux', $document->metadata_json['course']);
        $this->assertArrayNotHasKey('heap', $document->metadata_json);
        $this->assertArrayNotHasKey('document_id', $document->metadata_json);
        $this->assertArrayNotHasKey('owner_app', $document->metadata_json);
        $this->assertArrayNotHasKey('visibility', $document->metadata_json);
        $this->assertArrayNotHasKey('protected', $document->metadata_json);
        $this->assertSame('doc-spec-1', $document->metadata_json['__rawki']['audit']['documentId']);

        $this->assertDatabaseHas('documents', [
            'id' => 'doc-spec-1',
            'dataset_id' => 'heap-spec-docs',
            'title' => 'Updated Spec Document',
        ]);

        $this->withHeaders($auth)
            ->delete('/api/documents/doc-spec-1')
            ->assertNoContent();

        $this->withHeaders($auth)
            ->getJson('/api/documents/doc-spec-1')
            ->assertNotFound();

        $this->assertDatabaseMissing('documents', [
            'id' => 'doc-spec-1',
        ]);
    }

    public function test_canonical_v2_document_route_accepts_file_uploads_and_tracks_pipeline_identity(): void
    {
        $root = storage_path('framework/testing/specv2-file-upload');
        File::deleteDirectory($root);

        config()->set('temporal.storage.shared_root', $root);
        config()->set('file_converter.raganything_supported_extensions', ['pdf']);
        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');

        Http::fake([
            'http://bridge.test/temporal/workflows/ingest' => Http::response([
                'workflow_id' => 'ingest-source-spec-upload',
                'run_id' => 'spec-upload-run-1',
            ]),
        ]);

        ['token' => $token] = $this->issueApplicationToken([
            'id' => 'rawki-default',
            'tenant_id' => 'default',
        ]);

        Dataset::query()->create([
            'dataset_id' => 'heap-upload-docs',
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
            'name' => 'Heap Upload Docs',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => ['source' => 'upload'],
            'qdrant_collection' => 'heap_upload_docs',
            'neo4j_namespace' => 'heap_upload_docs',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/heaps/heap-upload-docs/documents', [
                'document_id' => 'doc-upload-1',
                'title' => 'Uploaded Spec Document',
                'metadata' => ['course' => 'design'],
                'file' => UploadedFile::fake()->create('brief.pdf', 12, 'application/pdf'),
            ], [
                'Accept' => 'application/json',
            ]);

        $response->assertCreated()
            ->assertJsonPath('documentId', 'doc-upload-1')
            ->assertJsonPath('heapId', 'heap-upload-docs')
            ->assertJsonPath('sourceType', Document::SOURCE_UPLOAD)
            ->assertJsonPath('status', Document::STATUS_QUEUED)
            ->assertJsonPath('metadata.course', 'design')
            ->assertJsonPath('taskId', $response->json('taskId'))
            ->assertJsonPath('jobId', $response->json('jobId'))
            ->assertJsonPath('sourceId', $response->json('sourceId'));

        $document = Document::query()->findOrFail('doc-upload-1');

        $this->assertSame(Document::SOURCE_UPLOAD, $document->source_type);
        $this->assertSame(Document::STATUS_QUEUED, $document->status);
        $this->assertNotNull($document->checksum_sha256);
        $this->assertSame('design', $document->metadata_json['course']);
        $this->assertSame($response->json('taskId'), $document->metadata_json['task_id']);
        $this->assertSame($response->json('jobId'), $document->metadata_json['job_id']);
        $this->assertSame($response->json('sourceId'), $document->metadata_json['upload']['source_id']);

        $this->assertDatabaseHas('pipeline_jobs', [
            'job_id' => $response->json('jobId'),
            'task_id' => $response->json('taskId'),
            'source_id' => $response->json('sourceId'),
            'source_url' => 'upload://brief.pdf',
            'status' => 'running',
        ]);

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'http://bridge.test/temporal/workflows/ingest'
            && data_get($request->data(), 'workflow_input.upload.original_filename') === 'brief.pdf'
            && data_get($request->data(), 'workflow_input.ingestion.graph') === false);

        File::deleteDirectory($root);
    }

    public function test_canonical_v2_delete_routes_return_intentional_status_codes(): void
    {
        ['token' => $token] = $this->issueApplicationToken([
            'id' => 'rawki-default',
            'tenant_id' => 'default',
        ]);

        $headers = ['Authorization' => 'Bearer '.$token];

        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');
        config()->set('config.qdrant_http_url', 'http://qdrant.test');
        config()->set('config.neo4j_http_url', 'http://neo4j.test');

        Http::fake([
            'http://bridge.test/documents/*' => Http::response(['ok' => true], 204),
            'http://qdrant.test/*' => Http::response(['status' => 'ok', 'result' => ['status' => 'acknowledged']], 200),
            'http://neo4j.test/db/neo4j/tx/commit' => Http::response([
                'results' => [
                    ['data' => [['row' => [0]]]],
                    ['data' => [['row' => [0, 0]]]],
                    ['data' => [['row' => [0]]]],
                ],
                'errors' => [],
            ], 200),
        ]);

        Dataset::query()->create([
            'dataset_id' => 'heap-delete-doc',
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
            'name' => 'Heap Delete Document',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => ['purpose' => 'document-delete-status'],
            'qdrant_collection' => 'heap_delete_doc',
            'neo4j_namespace' => 'heap_delete_doc',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Document::query()->create([
            'id' => 'doc-status-delete',
            'dataset_id' => 'heap-delete-doc',
            'collection' => 'heap_delete_doc',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/doc-status-delete',
            'storage_path' => '/tmp/doc-status-delete.md',
            'checksum_sha256' => hash('sha256', 'doc-status-delete'),
            'status' => Document::STATUS_COMPLETED,
        ]);

        Dataset::query()->create([
            'dataset_id' => 'heap-status-delete',
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
            'name' => 'Heap Delete Status',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => ['purpose' => 'heap-delete-status'],
            'qdrant_collection' => 'heap_status_delete',
            'neo4j_namespace' => 'heap_status_delete',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders($headers)
            ->delete('/api/documents/doc-status-delete')
            ->assertNoContent();

        $this->assertDatabaseMissing('documents', [
            'id' => 'doc-status-delete',
        ]);

        $this->withHeaders($headers)
            ->delete('/api/heaps/heap-status-delete')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('datasets', [
            'dataset_id' => 'heap-status-delete',
        ]);
    }

    public function test_application_actor_defaults_ownership_without_human_identity_provisioning(): void
    {
        $this->actingAsApplication([
            'id' => 'rawki-default',
            'tenant_id' => 'default',
        ]);

        $this->postJson('/api/heaps', [
            'id' => 'heap-local',
            'name' => 'Local Heap',
        ])->assertCreated()
            ->assertJsonPath('tenantId', 'default')
            ->assertJsonPath('ownerApp', 'rawki-default');

        $this->postJson('/api/auth/groups', [
            'id' => 'local_team',
            'name' => 'Local Team',
        ])->assertCreated()
            ->assertJsonPath('ownerApp', 'rawki-default');

        $this->assertSame(0, UserIdentity::query()->count());
    }

    public function test_heap_protected_inputs_are_ignored_when_authorization_is_disabled(): void
    {
        config()->set('authz.enabled', false);

        $this->actingAsApplication([
            'id' => 'rawki-default',
            'tenant_id' => 'default',
        ]);

        $this->postJson('/api/heaps', [
            'id' => 'heap-no-authz',
            'name' => 'Heap No Authz',
            'protected' => true,
        ])->assertCreated()
            ->assertJsonPath('id', 'heap-no-authz')
            ->assertJsonPath('protected', false);

        $this->patchJson('/api/heaps/heap-no-authz', [
            'protected' => true,
        ])->assertOk()
            ->assertJsonPath('id', 'heap-no-authz')
            ->assertJsonPath('protected', false);

        $this->assertDatabaseHas('datasets', [
            'dataset_id' => 'heap-no-authz',
            'protected' => false,
        ]);
    }

    public function test_heap_metadata_change_preserves_denormalized_search_payload_contract(): void
    {
        config()->set('authz.enabled', true);

        $this->actingAsApplication([
            'id' => 'rawki-default',
            'tenant_id' => 'default',
        ]);

        config()->set('config.qdrant_http_url', 'http://qdrant.test');
        Http::fake([
            'http://qdrant.test/*' => Http::response(['status' => 'ok', 'result' => ['status' => 'acknowledged']], 200),
        ]);

        Dataset::query()->create([
            'dataset_id' => 'heap-sync',
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
            'name' => 'Heap Sync',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => ['course' => 'design'],
            'qdrant_collection' => 'heap_sync',
            'neo4j_namespace' => 'heap_sync',
        ]);

        $document = Document::query()->create([
            'dataset_id' => 'heap-sync',
            'collection' => 'heap_sync',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/heap-sync',
            'storage_path' => '/tmp/heap-sync.md',
            'checksum_sha256' => hash('sha256', 'heap-sync'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => ['document_topic' => 'studio'],
        ]);

        $this->patchJson('/api/heaps/heap-sync', [
            'metadata' => ['course' => 'architecture'],
        ])->assertOk()
            ->assertJsonPath('metadata.course', 'architecture');

        $document->refresh();

        $this->assertSame('studio', $document->metadata_json['document_topic']);
        $this->assertSame('heap-sync', $document->metadata_json['__rawki']['audit']['heap']);
        $this->assertSame((string) $document->id, $document->metadata_json['__rawki']['audit']['documentId']);
        $this->assertSame(1, $document->metadata_json['__rawki']['audit']['schema']);
        $this->assertArrayNotHasKey('search_payload', $document->metadata_json['__rawki']);
        $this->assertArrayNotHasKey('heap_context', $document->metadata_json['__rawki']);
        $this->assertArrayNotHasKey('course', $document->metadata_json);
        $this->assertSame('architecture', $document->heap->metadata_json['course']);

        Http::assertSent(function (ClientRequest $request) use ($document): bool {
            $payloadKeys = array_keys($request['payload'] ?? []);
            sort($payloadKeys);
            $expectedKeys = ['course', 'document_id', 'document_topic', 'heap', 'owner_app', 'protected', 'visibility'];
            sort($expectedKeys);

            return $request->method() === 'POST'
                && $request->url() === 'http://qdrant.test/collections/heap_sync/points/payload'
                && ($request['filter']['must'][0]['match']['value'] ?? null) === $document->id
                && $payloadKeys === $expectedKeys
                && ($request['payload']['course'] ?? null) === 'architecture'
                && ($request['payload']['document_topic'] ?? null) === 'studio'
                && ($request['payload']['visibility'] ?? null) === 'discoverable'
                && ($request['payload']['protected'] ?? null) === false;
        });
    }

    public function test_heap_protection_change_preserves_denormalized_search_payload_contract(): void
    {
        config()->set('authz.enabled', true);

        $this->actingAsApplication([
            'id' => 'rawki-default',
            'tenant_id' => 'default',
        ]);

        config()->set('config.qdrant_http_url', 'http://qdrant.test');
        Http::fake([
            'http://qdrant.test/*' => Http::response(['status' => 'ok', 'result' => ['status' => 'acknowledged']], 200),
        ]);

        Dataset::query()->create([
            'dataset_id' => 'heap-protection',
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
            'name' => 'Heap Protection',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => ['course' => 'design'],
            'qdrant_collection' => 'heap_protection',
            'neo4j_namespace' => 'heap_protection',
        ]);

        $document = Document::query()->create([
            'dataset_id' => 'heap-protection',
            'collection' => 'heap_protection',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/heap-protection',
            'storage_path' => '/tmp/heap-protection.md',
            'checksum_sha256' => hash('sha256', 'heap-protection'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => ['document_topic' => 'studio'],
        ]);

        $this->patchJson('/api/heaps/heap-protection', [
            'protected' => true,
            'visibility' => 'hidden',
        ])->assertOk()
            ->assertJsonPath('visibility', 'hidden')
            ->assertJsonPath('protected', true);

        $document->refresh();

        $this->assertSame('studio', $document->metadata_json['document_topic']);
        $this->assertSame('heap-protection', $document->metadata_json['__rawki']['audit']['heap']);
        $this->assertSame((string) $document->id, $document->metadata_json['__rawki']['audit']['documentId']);
        $this->assertSame(1, $document->metadata_json['__rawki']['audit']['schema']);
        $this->assertArrayNotHasKey('search_payload', $document->metadata_json['__rawki']);
        $this->assertArrayNotHasKey('document_id', $document->metadata_json);
        $this->assertArrayNotHasKey('heap', $document->metadata_json);
        $this->assertArrayNotHasKey('owner_app', $document->metadata_json);
        $this->assertArrayNotHasKey('visibility', $document->metadata_json);
        $this->assertArrayNotHasKey('protected', $document->metadata_json);

        Http::assertSent(function (ClientRequest $request) use ($document): bool {
            if ($request->method() !== 'POST' || $request->url() !== 'http://qdrant.test/collections/heap_protection/points/payload') {
                return false;
            }

            $payload = $request['payload'] ?? [];
            return is_array($payload)
                && ($request['filter']['must'][0]['match']['value'] ?? null) === $document->id
                && ($payload['visibility'] ?? null) === 'hidden'
                && ($payload['protected'] ?? null) === true
                && ($payload['course'] ?? null) === 'design'
                && ($payload['document_topic'] ?? null) === 'studio';
        });
    }

    public function test_document_move_between_heaps_refreshes_canonical_search_payload_and_old_collection_cleanup(): void
    {
        $this->actingAsApplication([
            'id' => 'rawki-default',
            'tenant_id' => 'default',
        ]);

        config()->set('config.qdrant_http_url', 'http://qdrant.test');
        Http::fake([
            'http://qdrant.test/*' => Http::response(['status' => 'ok', 'result' => ['status' => 'acknowledged']], 200),
        ]);

        Dataset::query()->create([
            'dataset_id' => 'heap-source',
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
            'name' => 'Heap Source',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => ['course' => 'source'],
            'qdrant_collection' => 'heap_source',
            'neo4j_namespace' => 'heap_source',
        ]);

        Dataset::query()->create([
            'dataset_id' => 'heap-target',
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
            'name' => 'Heap Target',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_HIDDEN,
            'protected' => true,
            'metadata_json' => ['course' => 'target'],
            'qdrant_collection' => 'heap_target',
            'neo4j_namespace' => 'heap_target',
        ]);

        $document = Document::query()->create([
            'dataset_id' => 'heap-source',
            'collection' => 'heap_source',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/move-me',
            'storage_path' => '/tmp/move-me.md',
            'checksum_sha256' => hash('sha256', 'move-me'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => ['topic' => 'migration'],
        ]);

        Http::fake([
            'http://qdrant.test/*' => Http::response(['status' => 'ok', 'result' => ['status' => 'acknowledged']], 200),
        ]);

        $document->dataset_id = 'heap-target';
        $document->collection = 'heap_target';
        $document->save();
        $document->refresh();

        $this->assertSame('heap-target', $document->metadata_json['__rawki']['audit']['heap']);
        $this->assertSame((string) $document->id, $document->metadata_json['__rawki']['audit']['documentId']);
        $this->assertSame(1, $document->metadata_json['__rawki']['audit']['schema']);
        $this->assertSame('target', $document->heap->metadata_json['course']);
        $this->assertSame('migration', $document->metadata_json['topic']);
        $this->assertArrayNotHasKey('search_payload', $document->metadata_json['__rawki']);

        Http::assertSent(function (ClientRequest $request) use ($document): bool {
            return $request->method() === 'POST'
                && $request->url() === 'http://qdrant.test/collections/heap_source/points/payload/delete'
                && ($request['filter']['must'][0]['match']['value'] ?? null) === $document->id;
        });

        Http::assertSent(function (ClientRequest $request) use ($document): bool {
            return $request->method() === 'POST'
                && $request->url() === 'http://qdrant.test/collections/heap_target/points/payload'
                && ($request['filter']['must'][0]['match']['value'] ?? null) === $document->id
                && ($request['payload']['heap'] ?? null) === 'heap-target'
                && ($request['payload']['course'] ?? null) === 'target'
                && ($request['payload']['topic'] ?? null) === 'migration'
                && ($request['payload']['protected'] ?? null) === true;
        });
    }

}
