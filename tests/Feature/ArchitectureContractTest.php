<?php

namespace Tests\Feature;

use App\Models\AuthorizationPermissionEvent;
use App\Models\Dataset;
use App\Models\Document;
use App\Models\SpecV2\Application;
use App\Services\Authorization\ApiActorScopeService;
use App\Models\UserIdentity;
use App\Models\SpecV2\Group;
use App\Models\SpecV2\Tenant;
use App\Models\SpecV2\Corpus;
use App\Services\Authorization\IdentityProvisioningService;
use App\Services\Authorization\PermissionSyncService;
use App\Services\Authorization\Values\LmsDocumentRelation;
use App\Services\Authorization\Values\LmsMembership;
use App\Services\SpecV2\Repositories\HeapGrantRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ArchitectureContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_permissions_define_v2_read_boundaries_consistently(): void
    {
        Tenant::query()->create(['id' => 'tenant-a', 'name' => 'Tenant A', 'metadata_json' => []]);
        Tenant::query()->create(['id' => 'tenant-b', 'name' => 'Tenant B', 'metadata_json' => []]);

        Application::query()->create([
            'id' => 'app-owned',
            'tenant_id' => 'tenant-a',
            'name' => 'App Owned',
            'permissions' => [Application::PERMISSION_READS],
            'token_hash' => null,
            'metadata_json' => [],
        ]);
        Application::query()->create([
            'id' => 'app-peer',
            'tenant_id' => 'tenant-a',
            'name' => 'App Peer',
            'permissions' => [Application::PERMISSION_READS],
            'token_hash' => null,
            'metadata_json' => [],
        ]);
        Application::query()->create([
            'id' => 'app-foreign',
            'tenant_id' => 'tenant-b',
            'name' => 'App Foreign',
            'permissions' => [Application::PERMISSION_READS],
            'token_hash' => null,
            'metadata_json' => [],
        ]);

        $this->seedHeapCorpusAndGroup('heap-owned', 'tenant-a', 'app-owned', 'corpus-owned', 'group-owned');
        $this->seedHeapCorpusAndGroup('heap-peer', 'tenant-a', 'app-peer', 'corpus-peer', 'group-peer');
        $this->seedHeapCorpusAndGroup('heap-foreign', 'tenant-b', 'app-foreign', 'corpus-foreign', 'group-foreign');

        ['token' => $readsToken] = $this->issueApplicationToken([
            'id' => 'app-owned',
            'tenant_id' => 'tenant-a',
            'permissions' => [Application::PERMISSION_READS],
        ]);
        ['token' => $tenantToken] = $this->issueApplicationToken([
            'id' => 'tenant-reader',
            'tenant_id' => 'tenant-a',
            'permissions' => [Application::PERMISSION_READS_ALL_APPS],
        ]);
        ['token' => $federatedToken] = $this->issueApplicationToken([
            'id' => 'federated-reader',
            'tenant_id' => 'tenant-a',
            'permissions' => [Application::PERMISSION_READS_FEDERATED],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/applications')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.id', 'app-owned');
        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/heaps')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.id', 'heap-owned');
        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/auth/groups')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.id', 'group-owned');
        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/tenants')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.id', 'tenant-a');
        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/corpora')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.id', 'corpus-owned');

        $this->withHeader('Authorization', 'Bearer '.$tenantToken)
            ->getJson('/api/heaps')
            ->assertOk()
            ->assertJsonPath('pagination.total', 2);
        $this->withHeader('Authorization', 'Bearer '.$tenantToken)
            ->getJson('/api/applications')
            ->assertOk()
            ->assertJsonPath('pagination.total', 4);
        $federatedHeaps = $this->withHeader('Authorization', 'Bearer '.$federatedToken)
            ->getJson('/api/heaps')
            ->assertOk();
        $this->assertContains('heap-owned', array_column($federatedHeaps->json('data') ?? [], 'id'));
        $this->assertContains('heap-peer', array_column($federatedHeaps->json('data') ?? [], 'id'));
        $this->assertContains('heap-foreign', array_column($federatedHeaps->json('data') ?? [], 'id'));

        $federatedTenants = $this->withHeader('Authorization', 'Bearer '.$federatedToken)
            ->getJson('/api/tenants')
            ->assertOk();
        $this->assertContains('tenant-a', array_column($federatedTenants->json('data') ?? [], 'id'));
        $this->assertContains('tenant-b', array_column($federatedTenants->json('data') ?? [], 'id'));

        $federatedCorpora = $this->withHeader('Authorization', 'Bearer '.$federatedToken)
            ->getJson('/api/corpora')
            ->assertOk();
        $this->assertContains('corpus-owned', array_column($federatedCorpora->json('data') ?? [], 'id'));
        $this->assertContains('corpus-peer', array_column($federatedCorpora->json('data') ?? [], 'id'));
        $this->assertContains('corpus-foreign', array_column($federatedCorpora->json('data') ?? [], 'id'));
    }

    public function test_reads_protected_and_optional_auth_control_non_search_read_surfaces(): void
    {
        config()->set('authz.enabled', true);

        $this->seedHeapCorpusAndGroup('heap-public', 'tenant-a', 'app-owned', 'corpus-public', 'group-public', false);
        $this->seedHeapCorpusAndGroup('heap-protected', 'tenant-a', 'app-owned', 'corpus-protected', 'group-protected', true);

        ['token' => $readsToken] = $this->issueApplicationToken([
            'id' => 'app-owned',
            'tenant_id' => 'tenant-a',
            'permissions' => [Application::PERMISSION_READS],
        ]);
        ['token' => $protectedToken] = $this->issueApplicationToken([
            'id' => 'app-protected',
            'tenant_id' => 'tenant-a',
            'permissions' => [Application::PERMISSION_READS, Application::PERMISSION_READS_PROTECTED],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/heaps')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/corpora')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/heaps/heap-protected')
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$protectedToken)
            ->getJson('/api/heaps')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);

        Application::query()->where('id', 'app-protected')->update(['permissions' => [Application::PERMISSION_READS_PROTECTED, Application::PERMISSION_READS_ALL_APPS]]);

        ['token' => $protectedTenantToken] = $this->issueApplicationToken([
            'id' => 'app-protected',
            'tenant_id' => 'tenant-a',
            'permissions' => [Application::PERMISSION_READS_ALL_APPS, Application::PERMISSION_READS_PROTECTED],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$protectedTenantToken)
            ->getJson('/api/heaps')
            ->assertOk()
            ->assertJsonPath('pagination.total', 2);
        $this->withHeader('Authorization', 'Bearer '.$protectedTenantToken)
            ->getJson('/api/corpora')
            ->assertOk()
            ->assertJsonPath('pagination.total', 2);

        config()->set('authz.enabled', false);

        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/heaps')
            ->assertOk()
            ->assertJsonPath('pagination.total', 2);
        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/corpora')
            ->assertOk()
            ->assertJsonPath('pagination.total', 2);
        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/heaps?protected=1')
            ->assertOk()
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_permission_sync_projects_native_grants_and_runtime_scope_does_not_depend_on_event_rows(): void
    {
        config()->set('authz.enabled', true);
        config()->set('authz.graph.backend', 'spicedb');
        config()->set('authz.graph.spicedb.api_url', 'http://spicedb.test');
        config()->set('authz.graph.spicedb.preshared_key', 'secret-token');
        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');
        Http::fake([
            'http://spicedb.test/*' => Http::response(['written_at' => ['token' => 'zed-token']], 200),
            'http://bridge.test/query' => Http::response(['ok' => true, 'count' => 0, 'hits' => []], 200),
        ]);

        ['application' => $application, 'token' => $token] = $this->issueApplicationToken([
            'id' => 'app-owned',
            'tenant_id' => 'tenant-a',
            'permissions' => [Application::PERMISSION_READS],
        ]);

        Dataset::query()->create([
            'dataset_id' => 'heap-protected',
            'tenant_id' => 'tenant-a',
            'owner_application_id' => $application->id,
            'name' => 'Protected Heap',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => true,
            'metadata_json' => [],
            'qdrant_collection' => 'protected_heap',
            'neo4j_namespace' => 'protected_heap',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $document = Document::query()->create([
            'id' => 'doc-protected',
            'dataset_id' => 'heap-protected',
            'collection' => 'protected_heap',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/protected',
            'storage_path' => '/tmp/protected.md',
            'checksum_sha256' => hash('sha256', 'protected'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => [],
        ]);

        app(PermissionSyncService::class)->sync(
            [new LmsMembership('local', 'learner-1', 'course-1', 'member')],
            [new LmsDocumentRelation('local', 'course-1', (string) $document->id)],
        );

        AuthorizationPermissionEvent::query()->delete();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/search', [
                'query' => 'protected',
                'user_identifier' => 'learner-1',
            ])->assertOk();

        Http::assertSent(function (ClientRequest $request) use ($document): bool {
            if ($request->url() !== 'http://bridge.test/query') {
                return false;
            }

            $filters = $request->data()['filters'] ?? [];

            return $this->payloadHasOnlyBridgeKeys($request->data())
                && $request->data()['limit'] === 5
                && $this->filterContains($filters, 'protected', true)
                && ($this->filterContains($filters, 'heap', 'heap-protected')
                    || $this->filterContainsDocumentId($filters, (string) $document->id));
        });
    }

    public function test_reads_federated_unions_only_supported_exact_identifier_matches(): void
    {
        config()->set('authz.enabled', true);
        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');

        Tenant::query()->create(['id' => 'tenant-a', 'name' => 'Tenant A', 'metadata_json' => []]);
        Tenant::query()->create(['id' => 'tenant-b', 'name' => 'Tenant B', 'metadata_json' => []]);
        Tenant::query()->create(['id' => 'tenant-c', 'name' => 'Tenant C', 'metadata_json' => []]);

        Application::query()->create([
            'id' => 'app-a',
            'tenant_id' => 'tenant-a',
            'name' => 'App A',
            'permissions' => [Application::PERMISSION_READS],
            'token_hash' => null,
            'metadata_json' => [],
        ]);
        Application::query()->create([
            'id' => 'app-b',
            'tenant_id' => 'tenant-b',
            'name' => 'App B',
            'permissions' => [Application::PERMISSION_READS],
            'token_hash' => null,
            'metadata_json' => [],
        ]);
        Application::query()->create([
            'id' => 'app-c',
            'tenant_id' => 'tenant-c',
            'name' => 'App C',
            'permissions' => [Application::PERMISSION_READS],
            'token_hash' => null,
            'metadata_json' => [],
        ]);

        $this->seedHeapCorpusAndGroup('heap-fed-a', 'tenant-a', 'app-a', 'corpus-fed-a', 'group-fed-a', true, 'doc-fed-a');
        $this->seedHeapCorpusAndGroup('heap-fed-b', 'tenant-b', 'app-b', 'corpus-fed-b', 'group-fed-b', true, 'doc-fed-b');
        $this->seedHeapCorpusAndGroup('heap-ambiguous-a', 'tenant-c', 'app-c', 'corpus-ambiguous-a', 'group-ambiguous-a', true, 'doc-ambiguous-a');
        $this->seedHeapCorpusAndGroup('heap-ambiguous-b', 'tenant-c', 'app-c', 'corpus-ambiguous-b', 'group-ambiguous-b', true, 'doc-ambiguous-b');

        $supportedA = app(IdentityProvisioningService::class)->userAssignments('tenant-a', 'app-a', ['shared-user']);
        $supportedB = app(IdentityProvisioningService::class)->userAssignments('tenant-b', 'app-b', ['shared-user']);
        $ambiguousMoodle = app(IdentityProvisioningService::class)->connectorMemberAssignments('tenant-c', 'app-c', 'moodle', ['ambiguous-user']);
        $ambiguousStudip = app(IdentityProvisioningService::class)->connectorMemberAssignments('tenant-c', 'app-c', 'studip', ['ambiguous-user']);

        app(HeapGrantRepository::class)->replaceUsers('heap-fed-a', $supportedA);
        app(HeapGrantRepository::class)->replaceUsers('heap-fed-b', $supportedB);
        app(HeapGrantRepository::class)->replaceUsers('heap-ambiguous-a', $ambiguousMoodle);
        app(HeapGrantRepository::class)->replaceUsers('heap-ambiguous-b', $ambiguousStudip);

        ['token' => $federatedToken] = $this->issueApplicationToken([
            'id' => 'federated-reader',
            'tenant_id' => 'tenant-a',
            'permissions' => [Application::PERMISSION_READS_FEDERATED],
        ]);

        $sharedHeaps = $this->withHeader('Authorization', 'Bearer '.$federatedToken)
            ->getJson('/api/auth/users/by-identifier/heaps?identifier='.urlencode('shared-user'))
            ->assertOk();

        $this->assertSame(['heap-fed-a', 'heap-fed-b'], $sharedHeaps->json('data'));
        $this->assertSame(2, $sharedHeaps->json('pagination.total'));

        $ambiguousHeaps = $this->withHeader('Authorization', 'Bearer '.$federatedToken)
            ->getJson('/api/auth/users/by-identifier/heaps?identifier='.urlencode('ambiguous-user'))
            ->assertOk();

        $this->assertSame([], $ambiguousHeaps->json('data'));
        $this->assertSame(0, $ambiguousHeaps->json('pagination.total'));

        Http::fake([
            'http://bridge.test/query' => Http::response(['ok' => true, 'count' => 0, 'hits' => []], 200),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$federatedToken)
            ->postJson('/api/search', [
                'query' => 'shared',
                'user_identifier' => 'shared-user',
            ])->assertOk();

        Http::assertSent(function (ClientRequest $request): bool {
            if ($request->url() !== 'http://bridge.test/query') {
                return false;
            }

            $filters = $request->data()['filters'] ?? [];

            return $this->payloadHasOnlyBridgeKeys($request->data())
                && $this->filterContains($filters, 'heap', 'heap-fed-a')
                && $this->filterContains($filters, 'heap', 'heap-fed-b')
                && ! $this->filterContains($filters, 'heap', 'heap-ambiguous-a')
                && ! $this->filterContains($filters, 'heap', 'heap-ambiguous-b');
        });

        Http::fake([
            'http://bridge.test/query' => Http::response(['ok' => true, 'count' => 0, 'hits' => []], 200),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$federatedToken)
            ->postJson('/api/search', [
                'query' => 'ambiguous',
                'user_identifier' => 'ambiguous-user',
            ])->assertOk();

        Http::assertSent(function (ClientRequest $request): bool {
            if ($request->url() !== 'http://bridge.test/query') {
                return false;
            }

            $filters = $request->data()['filters'] ?? [];

            return $this->payloadHasOnlyBridgeKeys($request->data())
                && ! $this->filterContains($filters, 'heap', 'heap-ambiguous-a')
                && ! $this->filterContains($filters, 'heap', 'heap-ambiguous-b');
        });

        $this->assertDatabaseHas('user_identities', [
            'tenant_id' => 'tenant-a',
            'provider' => UserIdentity::PROVIDER_TENANT_IDENTITY,
            'external_user_id' => 'shared-user',
        ]);
        $this->assertDatabaseHas('user_identities', [
            'tenant_id' => 'tenant-b',
            'provider' => UserIdentity::PROVIDER_TENANT_IDENTITY,
            'external_user_id' => 'shared-user',
        ]);
    }

    public function test_application_permission_matrix_covers_read_surfaces_consistently(): void
    {
        config()->set('authz.enabled', true);

        Tenant::query()->create(['id' => 'tenant-a', 'name' => 'Tenant A', 'metadata_json' => []]);
        Tenant::query()->create(['id' => 'tenant-b', 'name' => 'Tenant B', 'metadata_json' => []]);

        Application::query()->create([
            'id' => 'app-owned',
            'tenant_id' => 'tenant-a',
            'name' => 'App Owned',
            'permissions' => [Application::PERMISSION_READS],
            'token_hash' => null,
            'metadata_json' => [],
        ]);
        Application::query()->create([
            'id' => 'app-peer',
            'tenant_id' => 'tenant-a',
            'name' => 'App Peer',
            'permissions' => [Application::PERMISSION_READS],
            'token_hash' => null,
            'metadata_json' => [],
        ]);
        Application::query()->create([
            'id' => 'app-foreign',
            'tenant_id' => 'tenant-b',
            'name' => 'App Foreign',
            'permissions' => [Application::PERMISSION_READS],
            'token_hash' => null,
            'metadata_json' => [],
        ]);

        $this->seedHeapCorpusAndGroup('heap-owned-public', 'tenant-a', 'app-owned', 'corpus-owned-public', 'group-owned-public', false, 'doc-owned-public');
        $this->seedHeapCorpusAndGroup('heap-owned-protected', 'tenant-a', 'app-owned', 'corpus-owned-protected', 'group-owned-protected', true, 'doc-owned-protected');
        $this->seedHeapCorpusAndGroup('heap-peer-public', 'tenant-a', 'app-peer', 'corpus-peer-public', 'group-peer-public', false, 'doc-peer-public');
        $this->seedHeapCorpusAndGroup('heap-peer-protected', 'tenant-a', 'app-peer', 'corpus-peer-protected', 'group-peer-protected', true, 'doc-peer-protected');
        $this->seedHeapCorpusAndGroup('heap-foreign-public', 'tenant-b', 'app-foreign', 'corpus-foreign-public', 'group-foreign-public', false, 'doc-foreign-public');

        ['token' => $readsToken] = $this->issueApplicationToken([
            'id' => 'app-owned',
            'tenant_id' => 'tenant-a',
            'permissions' => [Application::PERMISSION_READS],
        ]);
        ['token' => $tenantToken] = $this->issueApplicationToken([
            'id' => 'tenant-reader',
            'tenant_id' => 'tenant-a',
            'permissions' => [Application::PERMISSION_READS_ALL_APPS],
        ]);
        ['token' => $federatedToken] = $this->issueApplicationToken([
            'id' => 'federated-reader',
            'tenant_id' => 'tenant-a',
            'permissions' => [Application::PERMISSION_READS_FEDERATED],
        ]);
        ['token' => $protectedTenantToken] = $this->issueApplicationToken([
            'id' => 'tenant-reader-protected',
            'tenant_id' => 'tenant-a',
            'permissions' => [Application::PERMISSION_READS_ALL_APPS, Application::PERMISSION_READS_PROTECTED],
        ]);

        $cases = [
            [
                'name' => 'reads',
                'token' => $readsToken,
                'heap_total' => 1,
                'heap_statuses' => [
                    'heap-owned-public' => 200,
                    'heap-owned-protected' => 403,
                    'heap-peer-public' => 403,
                    'heap-foreign-public' => 403,
                ],
                'corpus_statuses' => [
                    'corpus-owned-public' => 200,
                    'corpus-owned-protected' => 403,
                    'corpus-peer-public' => 403,
                    'corpus-foreign-public' => 403,
                ],
                'grant_statuses' => [
                    'heap-owned-public' => 200,
                    'heap-owned-protected' => 200,
                    'heap-peer-public' => 403,
                    'heap-foreign-public' => 403,
                ],
                'document_access' => [
                    'doc-owned-public' => true,
                    'doc-owned-protected' => false,
                    'doc-peer-public' => false,
                    'doc-peer-protected' => false,
                    'doc-foreign-public' => false,
                ],
            ],
            [
                'name' => 'tenant',
                'token' => $tenantToken,
                'heap_total' => 2,
                'heap_statuses' => [
                    'heap-owned-public' => 200,
                    'heap-owned-protected' => 403,
                    'heap-peer-public' => 200,
                    'heap-peer-protected' => 403,
                    'heap-foreign-public' => 403,
                ],
                'corpus_statuses' => [
                    'corpus-owned-public' => 200,
                    'corpus-owned-protected' => 403,
                    'corpus-peer-public' => 200,
                    'corpus-peer-protected' => 403,
                    'corpus-foreign-public' => 403,
                ],
                'grant_statuses' => [
                    'heap-owned-public' => 200,
                    'heap-owned-protected' => 200,
                    'heap-peer-public' => 200,
                    'heap-peer-protected' => 200,
                    'heap-foreign-public' => 403,
                ],
                'document_access' => [
                    'doc-owned-public' => true,
                    'doc-owned-protected' => false,
                    'doc-peer-public' => true,
                    'doc-peer-protected' => false,
                    'doc-foreign-public' => false,
                ],
            ],
            [
                'name' => 'federated',
                'token' => $federatedToken,
                'heap_total' => 4,
                'heap_statuses' => [
                    'heap-owned-public' => 200,
                    'heap-owned-protected' => 403,
                    'heap-peer-public' => 200,
                    'heap-peer-protected' => 403,
                    'heap-foreign-public' => 200,
                ],
                'corpus_statuses' => [
                    'corpus-owned-public' => 200,
                    'corpus-owned-protected' => 403,
                    'corpus-peer-public' => 200,
                    'corpus-peer-protected' => 403,
                    'corpus-foreign-public' => 200,
                ],
                'grant_statuses' => [
                    'heap-owned-public' => 200,
                    'heap-owned-protected' => 200,
                    'heap-peer-public' => 200,
                    'heap-peer-protected' => 200,
                    'heap-foreign-public' => 200,
                ],
                'document_access' => [
                    'doc-owned-public' => true,
                    'doc-owned-protected' => false,
                    'doc-peer-public' => true,
                    'doc-peer-protected' => false,
                    'doc-foreign-public' => true,
                ],
            ],
            [
                'name' => 'tenant-protected',
                'token' => $protectedTenantToken,
                'heap_total' => 4,
                'heap_statuses' => [
                    'heap-owned-public' => 200,
                    'heap-owned-protected' => 200,
                    'heap-peer-public' => 200,
                    'heap-peer-protected' => 200,
                    'heap-foreign-public' => 403,
                ],
                'corpus_statuses' => [
                    'corpus-owned-public' => 200,
                    'corpus-owned-protected' => 200,
                    'corpus-peer-public' => 200,
                    'corpus-peer-protected' => 200,
                    'corpus-foreign-public' => 403,
                ],
                'grant_statuses' => [
                    'heap-owned-public' => 200,
                    'heap-owned-protected' => 200,
                    'heap-peer-public' => 200,
                    'heap-peer-protected' => 200,
                    'heap-foreign-public' => 403,
                ],
                'document_access' => [
                    'doc-owned-public' => true,
                    'doc-owned-protected' => true,
                    'doc-peer-public' => true,
                    'doc-peer-protected' => true,
                    'doc-foreign-public' => false,
                ],
            ],
        ];

        foreach ($cases as $case) {
            $heaps = $this->withHeader('Authorization', 'Bearer '.$case['token'])
                ->getJson('/api/heaps')
                ->assertOk();
            $this->assertSame(
                $case['heap_total'],
                $heaps->json('pagination.total'),
                'Unexpected heap total for '.$case['name'].': '.json_encode(array_column($heaps->json('data') ?? [], 'id'))
            );

            foreach ($case['heap_statuses'] as $heapId => $status) {
                $this->withHeader('Authorization', 'Bearer '.$case['token'])
                    ->getJson('/api/heaps/'.$heapId)
                    ->assertStatus($status);
            }

            foreach ($case['corpus_statuses'] as $corpusId => $status) {
                $this->withHeader('Authorization', 'Bearer '.$case['token'])
                    ->getJson('/api/corpora/'.$corpusId)
                    ->assertStatus($status);
            }

            foreach ($case['grant_statuses'] as $heapId => $status) {
                $this->withHeader('Authorization', 'Bearer '.$case['token'])
                    ->getJson('/api/auth/heaps/'.$heapId)
                    ->assertStatus($status);
            }

            foreach ($case['document_access'] as $documentId => $expected) {
                $this->assertSame($expected, $this->canReadDocumentWithToken($case['token'], $documentId), 'Unexpected document access for '.$documentId);
            }
        }
    }

    public function test_application_permission_matrix_covers_search_filters(): void
    {
        config()->set('authz.enabled', true);
        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');

        Tenant::query()->create(['id' => 'tenant-a', 'name' => 'Tenant A', 'metadata_json' => []]);
        Tenant::query()->create(['id' => 'tenant-b', 'name' => 'Tenant B', 'metadata_json' => []]);

        Application::query()->create([
            'id' => 'app-owned',
            'tenant_id' => 'tenant-a',
            'name' => 'App Owned',
            'permissions' => [Application::PERMISSION_READS],
            'token_hash' => null,
            'metadata_json' => [],
        ]);
        Application::query()->create([
            'id' => 'app-peer',
            'tenant_id' => 'tenant-a',
            'name' => 'App Peer',
            'permissions' => [Application::PERMISSION_READS],
            'token_hash' => null,
            'metadata_json' => [],
        ]);
        Application::query()->create([
            'id' => 'app-foreign',
            'tenant_id' => 'tenant-b',
            'name' => 'App Foreign',
            'permissions' => [Application::PERMISSION_READS],
            'token_hash' => null,
            'metadata_json' => [],
        ]);

        $this->seedHeapCorpusAndGroup('heap-owned-public', 'tenant-a', 'app-owned', 'corpus-owned-public', 'group-owned-public');
        $this->seedHeapCorpusAndGroup('heap-owned-protected', 'tenant-a', 'app-owned', 'corpus-owned-protected', 'group-owned-protected', true);
        $this->seedHeapCorpusAndGroup('heap-peer-public', 'tenant-a', 'app-peer', 'corpus-peer-public', 'group-peer-public');
        $this->seedHeapCorpusAndGroup('heap-peer-protected', 'tenant-a', 'app-peer', 'corpus-peer-protected', 'group-peer-protected', true);
        $this->seedHeapCorpusAndGroup('heap-foreign-public', 'tenant-b', 'app-foreign', 'corpus-foreign-public', 'group-foreign-public');

        ['token' => $readsToken] = $this->issueApplicationToken([
            'id' => 'app-owned',
            'tenant_id' => 'tenant-a',
            'permissions' => [Application::PERMISSION_READS],
        ]);
        ['token' => $tenantToken] = $this->issueApplicationToken([
            'id' => 'tenant-reader',
            'tenant_id' => 'tenant-a',
            'permissions' => [Application::PERMISSION_READS_ALL_APPS],
        ]);
        ['token' => $federatedToken] = $this->issueApplicationToken([
            'id' => 'federated-reader',
            'tenant_id' => 'tenant-a',
            'permissions' => [Application::PERMISSION_READS_FEDERATED],
        ]);
        ['token' => $protectedTenantToken] = $this->issueApplicationToken([
            'id' => 'tenant-reader-protected',
            'tenant_id' => 'tenant-a',
            'permissions' => [Application::PERMISSION_READS_ALL_APPS, Application::PERMISSION_READS_PROTECTED],
        ]);

        $captured = [];
        Http::fake([
            'http://bridge.test/query' => function (ClientRequest $request) use (&$captured) {
                $captured[] = $request->data();

                return Http::response(['ok' => true, 'count' => 0, 'hits' => []], 200);
            },
        ]);

        $cases = [
            [
                'token' => $readsToken,
                'assert' => function (array $payload): void {
                    $this->assertTrue($this->payloadHasOnlyBridgeKeys($payload));
                    $filters = $payload['filters'] ?? [];
                    $this->assertTrue($this->filterContains($filters, 'owner_app', 'app-owned'));
                    $this->assertTrue($this->filterContains($filters, 'protected', false));
                },
            ],
            [
                'token' => $tenantToken,
                'assert' => function (array $payload): void {
                    $this->assertTrue($this->payloadHasOnlyBridgeKeys($payload));
                    $filters = $payload['filters'] ?? [];
                    $this->assertTrue($this->filterContains($filters, 'heap', 'heap-owned-public'));
                    $this->assertTrue($this->filterContains($filters, 'heap', 'heap-peer-public'));
                    $this->assertFalse($this->filterContains($filters, 'heap', 'heap-foreign-public'));
                    $this->assertTrue($this->filterContains($filters, 'protected', false));
                    $this->assertFalse($this->filterContains($filters, 'owner_app', 'app-owned'));
                },
            ],
            [
                'token' => $federatedToken,
                'assert' => function (array $payload): void {
                    $this->assertTrue($this->payloadHasOnlyBridgeKeys($payload));
                    $filters = $payload['filters'] ?? [];
                    $this->assertTrue($this->filterContains($filters, 'protected', false));
                    $this->assertFalse($this->filterContains($filters, 'owner_app', 'app-owned'));
                    $this->assertFalse($this->filterContains($filters, 'heap', 'heap-owned-public'));
                },
            ],
            [
                'token' => $protectedTenantToken,
                'assert' => function (array $payload): void {
                    $this->assertTrue($this->payloadHasOnlyBridgeKeys($payload));
                    $filters = $payload['filters'] ?? [];
                    $this->assertTrue($this->filterContains($filters, 'heap', 'heap-owned-public'));
                    $this->assertTrue($this->filterContains($filters, 'heap', 'heap-peer-public'));
                    $this->assertFalse($this->filterContains($filters, 'heap', 'heap-foreign-public'));
                    $this->assertFalse($this->filterContains($filters, 'protected', false));
                },
            ],
        ];

        foreach ($cases as $case) {
            $this->withHeader('Authorization', 'Bearer '.$case['token'])
                ->postJson('/api/search', ['query' => 'design'])
                ->assertOk();

            $payload = array_pop($captured);
            $this->assertIsArray($payload);
            $case['assert']($payload);
        }
    }

    public function test_v2_read_endpoints_use_403_for_scope_denials_and_404_for_missing_resources(): void
    {
        config()->set('authz.enabled', true);

        Tenant::query()->create(['id' => 'tenant-a', 'name' => 'Tenant A', 'metadata_json' => []]);
        Tenant::query()->create(['id' => 'tenant-b', 'name' => 'Tenant B', 'metadata_json' => []]);

        Application::query()->create([
            'id' => 'app-owned',
            'tenant_id' => 'tenant-a',
            'name' => 'App Owned',
            'permissions' => [Application::PERMISSION_READS],
            'token_hash' => null,
            'metadata_json' => [],
        ]);
        Application::query()->create([
            'id' => 'app-peer',
            'tenant_id' => 'tenant-a',
            'name' => 'App Peer',
            'permissions' => [Application::PERMISSION_READS],
            'token_hash' => null,
            'metadata_json' => [],
        ]);

        $this->seedHeapCorpusAndGroup('heap-owned', 'tenant-a', 'app-owned', 'corpus-owned', 'group-owned');
        $this->seedHeapCorpusAndGroup('heap-peer', 'tenant-a', 'app-peer', 'corpus-peer', 'group-peer');

        ['token' => $readsToken] = $this->issueApplicationToken([
            'id' => 'app-owned',
            'tenant_id' => 'tenant-a',
            'permissions' => [Application::PERMISSION_READS],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/heaps/heap-peer')
            ->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/heaps/heap-missing')
            ->assertNotFound();

        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/corpora/corpus-peer')
            ->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/corpora/corpus-missing')
            ->assertNotFound();

        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/applications/app-peer')
            ->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/applications/app-missing')
            ->assertNotFound();

        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/tenants/tenant-b')
            ->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/tenants/tenant-missing')
            ->assertNotFound();

        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/auth/groups/group-peer')
            ->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$readsToken)
            ->getJson('/api/auth/groups/group-missing')
            ->assertNotFound();
    }

    public function test_denormalized_document_payload_contract_keeps_required_fields(): void
    {
        Dataset::query()->create([
            'dataset_id' => 'heap-design',
            'tenant_id' => 'tenant-a',
            'owner_application_id' => 'app-owned',
            'name' => 'Heap Design',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_HIDDEN,
            'protected' => true,
            'metadata_json' => ['course' => 'design'],
            'qdrant_collection' => 'heap_design',
            'neo4j_namespace' => 'heap_design',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $document = Document::query()->create([
            'dataset_id' => 'heap-design',
            'collection' => 'heap_design',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/design',
            'storage_path' => '/tmp/design.md',
            'checksum_sha256' => hash('sha256', 'design'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => ['topic' => 'studio'],
        ]);

        $document = $document->fresh();
        $payload = $document->metadata_json;

        $this->assertSame('studio', $payload['topic']);
        $this->assertArrayNotHasKey('course', $payload);
        $this->assertArrayNotHasKey('heap', $payload);
        $this->assertArrayNotHasKey('document_id', $payload);
        $this->assertArrayNotHasKey('owner_app', $payload);
        $this->assertArrayNotHasKey('visibility', $payload);
        $this->assertArrayNotHasKey('protected', $payload);

        $this->assertArrayHasKey('__rawki', $payload);
        $this->assertArrayHasKey('audit', $payload['__rawki']);
        $this->assertSame(1, $payload['__rawki']['audit']['schema']);
        $this->assertSame('heap-design', $payload['__rawki']['audit']['heap']);
        $this->assertSame((string) $document->id, $payload['__rawki']['audit']['documentId']);
    }

    public function test_internal_architecture_contract_and_critical_files_match_boundary_rules(): void
    {
        $contract = file_get_contents(base_path('docs/internal-architecture-contract.md'));
        $searchContract = file_get_contents(base_path('docs/hawki-rag-v2-search-contract.md'));
        $proxy = file_get_contents(app_path('Http/Controllers/API/HawkiRagProxyController.php'));
        $retrieval = file_get_contents(app_path('Http/Controllers/API/OpenCompat/RetrievalController.php'));
        $compatService = file_get_contents(app_path('Services/OpenCompat/OpenCompatService.php'));
        $compatIngestService = file_get_contents(app_path('Services/OpenCompat/OpenCompatIngestService.php'));
        $specDocuments = file_get_contents(app_path('Services/SpecV2/DocumentService.php'));
        $actorScope = file_get_contents(app_path('Services/Authorization/ApiActorScopeService.php'));
        $actorResolver = file_get_contents(app_path('Services/Authorization/ApiActorResolver.php'));
        $internalApiRoutes = file_get_contents(base_path('routes/internal_api.php'));
        $appSearchRoutes = file_get_contents(base_path('routes/internal_api/app_search.php'));
        $appIngestionRoutes = file_get_contents(base_path('routes/internal_api/app_ingestion.php'));
        $specRoutes = file_get_contents(base_path('routes/internal_api/spec_v2.php'));
        $operatorRoutes = file_get_contents(base_path('routes/internal_api/operator.php'));
        $queryExecution = file_get_contents(base_path('python_rag/application/workflows/query_execution.py'));
        $payloadFactory = file_get_contents(app_path('Services/SpecV2/DocumentSearchPayloadFactory.php'));
        $payloadSync = file_get_contents(app_path('Services/SpecV2/DocumentSearchPayloadSyncService.php'));

        $this->assertIsString($contract);
        $this->assertStringContainsString('Laravel owns actor resolution', $contract);
        $this->assertStringContainsString('Python is a retrieval engine only', $contract);
        $this->assertStringContainsString('Qdrant payload mutation is a write-path concern', $contract);
        $this->assertStringContainsString('App-facing internal APIs use application bearer tokens only', $contract);
        $this->assertStringContainsString('App-facing V2 reads return `403`', $contract);
        $this->assertStringContainsString('No legacy compatibility API routes are registered', $contract);
        $this->assertStringContainsString('receives only `query`, `limit`, and a', $contract);

        $this->assertIsString($searchContract);
        $this->assertStringContainsString('The Python bridge request contains exactly', $searchContract);
        $this->assertStringContainsString('"query"', $searchContract);
        $this->assertStringContainsString('"limit"', $searchContract);
        $this->assertStringContainsString('"filters"', $searchContract);
        $this->assertStringContainsString('Python must not receive', $searchContract);

        $this->assertIsString($proxy);
        $this->assertStringContainsString('SearchQueryRequest', $proxy);
        $this->assertStringContainsString('GatewaySearchFilterService', $proxy);
        $this->assertStringNotContainsString('PermissionGraphClient', $proxy);

        $this->assertIsString($retrieval);
        $this->assertStringContainsString('SearchQueryRequest', $retrieval);
        $this->assertStringContainsString('GatewaySearchFilterService', $retrieval);
        $this->assertStringContainsString('OpenCompatService', $retrieval);
        $this->assertStringNotContainsString('OpenCompatDocumentService', $retrieval);
        $this->assertStringNotContainsString('searchDocuments(', $retrieval);
        $this->assertStringNotContainsString('batchDocuments(', $retrieval);

        $this->assertIsString($compatService);
        $this->assertStringContainsString('RagQueryPayloadFactory', $compatService);
        $this->assertStringNotContainsString("'generate' =>", $compatService);
        $this->assertStringNotContainsString("'preferred_tags' =>", $compatService);

        $this->assertIsString($compatIngestService);
        $this->assertStringNotContainsString('PipelineUploadInput', $compatIngestService);
        $this->assertStringNotContainsString('LegacyDatasetService', $compatIngestService);
        $this->assertStringContainsString('OpenCompatBridgeClient', $compatIngestService);

        $this->assertIsString($specDocuments);
        $this->assertStringContainsString('CorpusSyncService', $specDocuments);
        $this->assertStringContainsString('OpenCompatIngestService', $specDocuments);

        $this->assertIsString($actorScope);
        $this->assertStringNotContainsString('sanctum', $actorScope);
        $this->assertStringNotContainsString('oidc', $actorScope);
        $this->assertIsString($actorResolver);
        $this->assertStringContainsString('Application authentication is required.', $actorResolver);

        $this->assertIsString($internalApiRoutes);
        $this->assertStringNotContainsString('compatibility.php', $internalApiRoutes);
        $this->assertIsString($appSearchRoutes);
        $this->assertStringContainsString('auth:application-token', $appSearchRoutes);
        $this->assertStringNotContainsString('sanctum,oidc', $appSearchRoutes);
        $this->assertStringContainsString("Route::prefix('search')", $appSearchRoutes);
        $this->assertStringNotContainsString("Route::prefix('retrieve')", $appSearchRoutes);
        $this->assertStringNotContainsString("Route::post('/query'", $appSearchRoutes);
        $this->assertStringNotContainsString("Route::post('/documents'", $appSearchRoutes);
        $this->assertStringNotContainsString("Route::post('/batch/chunks'", $appSearchRoutes);
        $this->assertIsString($appIngestionRoutes);
        $this->assertStringContainsString('auth:application-token', $appIngestionRoutes);
        $this->assertIsString($specRoutes);
        $this->assertStringContainsString('auth:application-token', $specRoutes);
        $this->assertStringNotContainsString('sanctum,oidc', $specRoutes);
        $this->assertStringContainsString("/{heapId}/documents", $specRoutes);
        $this->assertStringContainsString("Route::get('/check'", $specRoutes);
        $this->assertStringContainsString("Route::prefix('groups')", $specRoutes);
        $this->assertIsString($operatorRoutes);
        $this->assertStringContainsString('auth:sanctum,oidc', $operatorRoutes);
        $this->assertStringNotContainsString('OpenCompatApiKeyController', $operatorRoutes);
        $this->assertStringNotContainsString('OpenCompatSystemController', $operatorRoutes);
        $this->assertStringNotContainsString("Route::post('/start'", $operatorRoutes);
        $this->assertStringNotContainsString("Route::post('/files'", $operatorRoutes);
        $this->assertStringNotContainsString("/datasets/{datasetId}/retry-failed", $operatorRoutes);

        $this->assertIsString($queryExecution);
        $this->assertStringNotContainsString('auth_context', $queryExecution);
        $this->assertStringNotContainsString('user_identifier', $queryExecution);

        $this->assertIsString($payloadFactory);
        $this->assertStringContainsString('DocumentStoredMetadata', $payloadFactory);
        $this->assertStringNotContainsString("'search_payload'", $payloadFactory);
        $this->assertStringNotContainsString("'heap_context'", $payloadFactory);

        $this->assertIsString($payloadSync);
        $this->assertStringContainsString('syncQdrantPayload', $payloadSync);
    }

    public function test_application_facing_routes_are_only_registered_on_approved_v2_and_shared_app_surfaces(): void
    {
        $allowedUris = [
            'api/applications',
            'api/applications/{applicationId}',
            'api/auth/check',
            'api/auth/documents/{documentId}',
            'api/auth/groups',
            'api/auth/groups/{groupId}',
            'api/auth/groups/{groupId}/users',
            'api/auth/heaps/{heapId}',
            'api/auth/users/by-identifier/heaps',
            'api/corpora',
            'api/corpora/{corpusId}',
            'api/documents/{documentId}',
            'api/heaps',
            'api/heaps/{heapId}',
            'api/heaps/{heapId}/documents',
            'api/pipeline/files',
            'api/search',
            'api/search/chunks',
            'api/search/chunks/grouped',
            'api/tenants',
            'api/tenants/{tenantId}',
        ];

        $uris = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (\Illuminate\Routing\Route $route): bool => in_array('auth:application-token', $route->gatherMiddleware(), true))
            ->map(fn (\Illuminate\Routing\Route $route): string => $route->uri())
            ->unique()
            ->sort()
            ->values()
            ->all();

        sort($allowedUris);

        $this->assertSame($allowedUris, $uris);
    }

    public function test_core_v2_and_app_contract_files_do_not_reintroduce_legacy_dataset_terms(): void
    {
        $contractFiles = [
            base_path('routes/internal_api/spec_v2.php'),
            base_path('routes/internal_api/app_ingestion.php'),
            base_path('routes/internal_api/app_search.php'),
            base_path('routes/internal_api/operator.php'),
            app_path('Http/Requests/Search/SearchQueryRequest.php'),
            app_path('Http/Middleware/SecurityHeaders.php'),
            app_path('Providers/AppServiceProvider.php'),
            app_path('Http/Controllers/SpecV2/ApplicationController.php'),
            app_path('Http/Controllers/SpecV2/HeapController.php'),
            app_path('Http/Controllers/SpecV2/TenantController.php'),
            app_path('Http/Requests/Document/ListDocumentsRequest.php'),
            app_path('Http/Requests/Pipeline/UploadPipelineFileRequest.php'),
            app_path('Http/Requests/Pipeline/ListFailedPipelineJobsRequest.php'),
            app_path('Http/Resources/SpecV2/ApplicationResource.php'),
            app_path('Http/Resources/SpecV2/HeapResource.php'),
            app_path('Http/Resources/SpecV2/DocumentResource.php'),
            app_path('Http/Resources/SpecV2/TenantResource.php'),
            app_path('Services/Authorization/ApiActorScopeService.php'),
            app_path('Services/Authorization/ApplicationReadPolicy.php'),
            app_path('Services/Authorization/Repositories/GrantAccessRepository.php'),
            app_path('Services/Document/DocumentPayloadBuilder.php'),
            app_path('Services/Document/DocumentRepository.php'),
            app_path('Services/Graph/GraphSourceDocumentResolver.php'),
            app_path('Services/Pipeline/Exceptions/PipelineUploadStorageException.php'),
            app_path('Services/Pipeline/Recovery/PipelineRecoveryInputNormalizer.php'),
            app_path('Services/Pipeline/Recovery/PipelineRecoveryPayloadService.php'),
            app_path('Services/Pipeline/Tasks/PipelineTaskPayloadService.php'),
            app_path('Services/Pipeline/Uploads/PipelineUploadResultFactory.php'),
            app_path('Services/Pipeline/Values/PipelineUploadInput.php'),
            app_path('Services/SpecV2/AuthorizationGrantService.php'),
            app_path('Services/SpecV2/DocumentSearchPayloadFactory.php'),
            app_path('Services/SpecV2/DocumentService.php'),
            app_path('Services/SpecV2/HeapDeletionService.php'),
            app_path('Services/SpecV2/HeapService.php'),
            app_path('Services/SpecV2/Repositories/HeapRepository.php'),
        ];

        $forbiddenTerms = [
            'datasetId',
            'dataset_id',
            'datasetDeleted',
            'datasets*',
            '/datasets',
            '{datasetId}',
            'currentCanReadDataset',
            'currentDatasetFilters',
            'retryDataset',
        ];

        foreach ($contractFiles as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents, 'Unable to read '.$file);

            foreach ($forbiddenTerms as $term) {
                $this->assertStringNotContainsString($term, $contents, $file.' still exposes legacy terminology: '.$term);
            }
        }

        $this->assertFileExists(app_path('Services/Heap/LegacyDatasetHeapAdapter.php'));

        foreach ([
            app_path('Services/Authorization/ApiActorScopeService.php'),
            app_path('Services/Authorization/ApplicationReadPolicy.php'),
            app_path('Services/SpecV2/HeapDeletionService.php'),
        ] as $file) {
            $contents = file_get_contents($file);
            $this->assertIsString($contents, 'Unable to read '.$file);
            $this->assertStringNotContainsString('use App\Models\Dataset;', $contents, $file.' should use the explicit legacy heap adapter instead of the dataset model directly.');
            $this->assertStringNotContainsString('new Dataset(', $contents, $file.' should use the explicit legacy heap adapter instead of constructing dataset models directly.');
        }

        $applicationUris = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (\Illuminate\Routing\Route $route): bool => in_array('auth:application-token', $route->gatherMiddleware(), true))
            ->map(fn (\Illuminate\Routing\Route $route): string => $route->uri())
            ->implode("\n");

        $this->assertStringNotContainsString('dataset', $applicationUris);
    }

    public function test_spec_v2_http_responses_use_resources_instead_of_stale_payload_builders(): void
    {
        foreach ([
            app_path('Services/SpecV2/Payloads/ApplicationPayloadBuilder.php'),
            app_path('Services/SpecV2/Payloads/CorpusPayloadBuilder.php'),
            app_path('Services/SpecV2/Payloads/GroupPayloadBuilder.php'),
            app_path('Services/SpecV2/Payloads/HeapPayloadBuilder.php'),
            app_path('Services/SpecV2/Payloads/TenantPayloadBuilder.php'),
        ] as $removedPayloadBuilder) {
            $this->assertFileDoesNotExist($removedPayloadBuilder);
        }

        foreach ([
            app_path('Http/Controllers/SpecV2/ApplicationController.php') => ['ApplicationResource', 'ApplicationCollection'],
            app_path('Http/Controllers/SpecV2/CorpusController.php') => ['CorpusResource', 'CorpusCollection'],
            app_path('Http/Controllers/SpecV2/GroupController.php') => ['GroupResource', 'GroupCollection'],
            app_path('Http/Controllers/SpecV2/HeapController.php') => ['HeapResource', 'HeapCollection'],
            app_path('Http/Controllers/SpecV2/TenantController.php') => ['TenantResource', 'TenantCollection'],
        ] as $controller => $resourceNames) {
            $contents = file_get_contents($controller);
            $this->assertIsString($contents, 'Unable to read '.$controller);
            $this->assertStringNotContainsString('PayloadBuilder', $contents, $controller.' should serialize through API resources.');

            foreach ($resourceNames as $resourceName) {
                $this->assertStringContainsString($resourceName, $contents, $controller.' should serialize through '.$resourceName.'.');
            }
        }
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function filterContains(array $filter, string $key, mixed $value): bool
    {
        if ($this->isFilterLeaf($filter)) {
            return $filter[0] === $key && $this->filterValueMatches($filter[1], $value);
        }

        if (array_key_exists($key, $filter)) {
            $candidate = $filter[$key];

            return $this->filterValueMatches($candidate, $value);
        }

        foreach (['AND', 'OR'] as $operator) {
            $children = $filter[$operator] ?? null;
            if (! is_array($children)) {
                continue;
            }

            foreach ($children as $child) {
                if (is_array($child) && $this->filterContains($child, $key, $value)) {
                    return true;
                }
            }
        }

        $not = $filter['NOT'] ?? null;
        if (is_array($not) && $this->filterContains($not, $key, $value)) {
            return true;
        }

        foreach ($filter as $candidate) {
            if (is_array($candidate) && $this->filterContains($candidate, $key, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function filterContainsDocumentId(array $filter, string $documentId): bool
    {
        return $this->filterContains($filter, 'document_id', $documentId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadHasOnlyBridgeKeys(array $payload): bool
    {
        $keys = array_keys($payload);
        sort($keys);

        return $keys === ['filters', 'limit', 'query'];
    }

    private function isFilterLeaf(array $filter): bool
    {
        return array_is_list($filter)
            && count($filter) === 2
            && is_string($filter[0] ?? null);
    }

    private function filterValueMatches(mixed $candidate, mixed $value): bool
    {
        if (is_array($candidate)) {
            return in_array($value, $candidate, true);
        }

        return $candidate === $value;
    }

    private function seedHeapCorpusAndGroup(
        string $heapId,
        string $tenantId,
        string $applicationId,
        string $corpusId,
        string $groupId,
        bool $protected = false,
        ?string $documentId = null,
    ): void {
        Dataset::query()->create([
            'dataset_id' => $heapId,
            'tenant_id' => $tenantId,
            'owner_application_id' => $applicationId,
            'name' => $heapId,
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => $protected,
            'metadata_json' => [],
            'qdrant_collection' => $heapId.'_collection',
            'neo4j_namespace' => $heapId.'_namespace',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Corpus::query()->create([
            'id' => $corpusId,
            'content' => 'Corpus '.$corpusId,
            'reference_count' => 1,
            'metadata_json' => [],
        ]);
        Document::query()->create([
            'id' => $documentId ?? 'doc-'.$heapId,
            'dataset_id' => $heapId,
            'corpus_id' => $corpusId,
            'collection' => $heapId.'_collection',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/'.$heapId,
            'storage_path' => '/tmp/'.$heapId.'.md',
            'checksum_sha256' => hash('sha256', $heapId),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => [],
        ]);
        Group::query()->create([
            'id' => $groupId,
            'tenant_id' => $tenantId,
            'owner_application_id' => $applicationId,
            'name' => $groupId,
            'metadata_json' => [],
        ]);
    }

    private function canReadDocumentWithToken(string $token, string $documentId): bool
    {
        $previousRequest = $this->app['request'];
        $request = HttpRequest::create('/api/test-document-scope', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ]);
        $this->app->instance('request', $request);

        try {
            return app(ApiActorScopeService::class)->currentCanReadDocument($documentId);
        } finally {
            $this->app->instance('request', $previousRequest);
        }
    }
}
