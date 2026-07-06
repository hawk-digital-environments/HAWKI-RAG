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
            ->postJson('/api/groups', [
            'id' => 'design_students',
            'name' => 'Design Students',
            'owner_application_id' => 'hawki-web',
        ])->assertCreated()
            ->assertJsonPath('id', 'hawki-web:design_students')
            ->assertJsonPath('ownerApp', 'hawki-web');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/groups/hawki-web:design_students/users', [
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

    public function test_existing_dataset_and_document_payloads_expose_heap_and_corpus_terms(): void
    {
        $this->actingAsApplication([
            'id' => 'rawki-default',
            'tenant_id' => 'default',
        ]);

        Dataset::query()->create([
            'dataset_id' => 'dataset-v2',
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
            'name' => 'Dataset V2',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => ['type' => 'wiki'],
            'qdrant_collection' => 'hawki_dataset_v2',
            'neo4j_namespace' => 'hawki_dataset_v2',
        ]);

        PipelineTask::query()->create([
            'task_id' => 'task-v2',
            'dataset_id' => 'dataset-v2',
            'status' => PipelineTask::STATUS_COMPLETED,
            'metadata' => [],
        ]);

        Corpus::query()->create([
            'id' => hash('sha256', 'dataset-v2-doc'),
            'content' => 'Dataset V2 corpus content.',
            'reference_count' => 1,
            'metadata_json' => [],
        ]);

        $document = Document::query()->create([
            'dataset_id' => 'dataset-v2',
            'corpus_id' => hash('sha256', 'dataset-v2-doc'),
            'collection' => 'hawki_dataset_v2',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/dataset-v2',
            'storage_path' => storage_path('framework/testing/specv2/dataset-v2.md'),
            'checksum_sha256' => hash('sha256', 'dataset-v2-doc'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => [],
        ]);

        $this->getJson('/api/datasets/dataset-v2')
            ->assertOk()
            ->assertJsonPath('dataset.heapId', 'dataset-v2')
            ->assertJsonPath('dataset.tenantId', 'default')
            ->assertJsonPath('dataset.ownerApp', 'rawki-default')
            ->assertJsonPath('dataset.metadata.type', 'wiki');

        $this->getJson('/api/documents/'.$document->id)
            ->assertOk()
            ->assertJsonPath('document.heapId', 'dataset-v2')
            ->assertJsonPath('document.corpusId', hash('sha256', 'dataset-v2-doc'));
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

        $create = $this->withHeader('Authorization', 'Bearer '.$token)
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

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/heaps/heap-spec-docs/documents')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.documentId', 'doc-spec-1');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/documents/doc-spec-1', [
                'content' => 'Updated design guidance.',
                'metadata' => ['course' => 'ux'],
                'title' => 'Updated Spec Document',
            ])->assertOk()
            ->assertJsonPath('documentId', 'doc-spec-1')
            ->assertJsonPath('metadata.course', 'ux')
            ->assertJsonPath('title', 'Updated Spec Document');

        $this->assertDatabaseHas('documents', [
            'id' => 'doc-spec-1',
            'dataset_id' => 'heap-spec-docs',
            'title' => 'Updated Spec Document',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->delete('/api/documents/doc-spec-1')
            ->assertNoContent();

        $this->assertDatabaseMissing('documents', [
            'id' => 'doc-spec-1',
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

        $this->postJson('/api/groups', [
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

    public function test_heap_updates_propagate_document_search_context_and_qdrant_payloads(): void
    {
        config()->set('authz.enabled', true);

        $this->actingAsApplication([
            'id' => 'rawki-default',
            'tenant_id' => 'default',
        ]);

        config()->set('config.qdrant_http_url', 'http://qdrant.test');

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
            'visibility' => 'hidden',
            'protected' => true,
            'metadata' => ['course' => 'architecture'],
        ])->assertOk()
            ->assertJsonPath('visibility', 'hidden')
            ->assertJsonPath('protected', true)
            ->assertJsonPath('metadata.course', 'architecture');

        $document->refresh();

        $this->assertSame('studio', $document->metadata_json['document_topic']);
        $this->assertSame('heap-sync', $document->metadata_json['__rawki']['heap_context']['heap']);
        $this->assertSame('hidden', $document->metadata_json['__rawki']['search_payload']['visibility']);
        $this->assertTrue($document->metadata_json['__rawki']['search_payload']['protected']);
        $this->assertSame('architecture', $document->metadata_json['__rawki']['search_payload']['course']);

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

        $this->assertSame('heap-target', $document->metadata_json['__rawki']['heap_context']['heap']);
        $this->assertSame('target', $document->metadata_json['__rawki']['search_payload']['course']);
        $this->assertTrue($document->metadata_json['__rawki']['search_payload']['protected']);

        Http::assertSent(function (ClientRequest $request) use ($document): bool {
            $keys = $request['keys'] ?? null;
            if (! is_array($keys)) {
                return false;
            }

            sort($keys);
            $expected = ['course', 'document_id', 'heap', 'owner_app', 'protected', 'topic', 'visibility'];
            sort($expected);

            return $request->method() === 'POST'
                && $request->url() === 'http://qdrant.test/collections/heap_source/points/payload/delete'
                && ($request['filter']['must'][0]['match']['value'] ?? null) === $document->id
                && $keys === $expected;
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
