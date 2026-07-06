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
        $this->actingAsApiUser();

        $this->postJson('/api/tenants', [
            'id' => 'uni-hawk',
            'name' => 'University Hawk',
            'metadata' => ['kind' => 'university'],
        ])->assertCreated()
            ->assertJsonPath('id', 'uni-hawk')
            ->assertJsonPath('metadata.kind', 'university');

        $this->postJson('/api/applications', [
            'id' => 'hawki-web',
            'tenant_id' => 'uni-hawk',
            'name' => 'HAWKI Web',
            'permissions' => ['reads-all-apps', 'reads'],
        ])->assertCreated()
            ->assertJsonPath('id', 'hawki-web')
            ->assertJsonPath('tenantId', 'uni-hawk')
            ->assertJsonPath('permissions.0', 'reads-all-apps')
            ->assertJsonPath('tokenType', 'Bearer');

        $this->postJson('/api/heaps', [
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

        $this->postJson('/api/groups', [
            'id' => 'design_students',
            'name' => 'Design Students',
            'owner_application_id' => 'hawki-web',
        ])->assertCreated()
            ->assertJsonPath('id', 'hawki-web:design_students')
            ->assertJsonPath('ownerApp', 'hawki-web');

        $this->putJson('/api/groups/hawki-web:design_students/users', [
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

        $this->getJson('/api/corpora')
            ->assertOk()
            ->assertJsonPath('data.0.id', hash('sha256', 'design corpus'))
            ->assertJsonPath('data.0.referenceCount', 1);

        $this->getJson('/api/corpora/'.hash('sha256', 'design corpus'))
            ->assertOk()
            ->assertJsonPath('id', hash('sha256', 'design corpus'))
            ->assertJsonPath('documentCount', 1);
    }

    public function test_existing_dataset_and_document_payloads_expose_heap_and_corpus_terms(): void
    {
        $this->actingAsApiUser();

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

    public function test_local_actor_is_provisioned_into_v2_identity_context_for_default_ownership(): void
    {
        $user = $this->actingAsApiUser();

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

        $identity = UserIdentity::query()
            ->where('user_id', $user->id)
            ->where('provider', 'local')
            ->first();

        $this->assertNotNull($identity);
        $this->assertSame('default', $identity->tenant_id);
        $this->assertSame('rawki-default', $identity->application_id);
        $this->assertNotNull($identity->internal_user_id);
        $this->assertDatabaseHas('internal_users', [
            'id' => $identity->internal_user_id,
            'tenant_id' => 'default',
        ]);
    }

    public function test_heap_updates_propagate_document_search_context_and_qdrant_payloads(): void
    {
        $this->actingAsApiUser();

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

        Http::assertSent(function (ClientRequest $request) use ($document): bool {
            return $request->method() === 'POST'
                && $request->url() === 'http://qdrant.test/collections/heap_sync/points/payload/delete'
                && ($request['filter']['must'][0]['match']['value'] ?? null) === $document->id
                && ($request['keys'] ?? null) === ['course', 'heap', 'document_id', 'owner_app', 'visibility', 'protected'];
        });

        Http::assertSent(function (ClientRequest $request) use ($document): bool {
            return $request->method() === 'POST'
                && $request->url() === 'http://qdrant.test/collections/heap_sync/points/payload'
                && ($request['filter']['must'][0]['match']['value'] ?? null) === $document->id
                && ($request['payload']['heap'] ?? null) === 'heap-sync'
                && ($request['payload']['document_id'] ?? null) === $document->id
                && ($request['payload']['visibility'] ?? null) === 'hidden'
                && ($request['payload']['protected'] ?? null) === true
                && ($request['payload']['course'] ?? null) === 'architecture';
        });
    }
}
