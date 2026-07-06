<?php

namespace Tests\Feature;

use App\Models\AuthorizationPermissionEvent;
use App\Models\Dataset;
use App\Models\Document;
use App\Models\SpecV2\Application;
use App\Models\SpecV2\Group;
use App\Models\SpecV2\Tenant;
use App\Models\SpecV2\Corpus;
use App\Services\Authorization\PermissionSyncService;
use App\Services\Authorization\Values\LmsDocumentRelation;
use App\Services\Authorization\Values\LmsMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
            ->getJson('/api/groups')
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
            ->assertNotFound();

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

        Http::assertSent(function (Request $request) use ($document): bool {
            if ($request->url() !== 'http://bridge.test/query') {
                return false;
            }

            $filters = $request->data()['filters'] ?? [];
            $docIds = array_map(
                static fn (array $match): ?string => $match['match']['value'] ?? null,
                is_array($filters['should'] ?? null) ? $filters['should'] : [],
            );

            return in_array($document->id, $docIds, true);
        });
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

        $payload = $document->fresh()->metadata_json['__rawki']['search_payload'] ?? [];

        foreach (['heap', 'document_id', 'owner_app', 'visibility', 'protected'] as $field) {
            $this->assertArrayHasKey($field, $payload);
        }

        $this->assertSame('heap-design', $payload['heap']);
        $this->assertSame((string) $document->id, $payload['document_id']);
        $this->assertSame('app-owned', $payload['owner_app']);
        $this->assertSame('hidden', $payload['visibility']);
        $this->assertTrue($payload['protected']);
        $this->assertSame('studio', $payload['topic']);
        $this->assertSame('design', $payload['course']);
    }

    public function test_internal_architecture_contract_and_critical_files_match_boundary_rules(): void
    {
        $contract = file_get_contents(base_path('docs/internal-architecture-contract.md'));
        $proxy = file_get_contents(app_path('Http/Controllers/API/HawkiRagProxyController.php'));
        $retrieval = file_get_contents(app_path('Http/Controllers/API/OpenCompat/RetrievalController.php'));
        $compatDocumentService = file_get_contents(app_path('Services/OpenCompat/OpenCompatDocumentService.php'));
        $compatIngestService = file_get_contents(app_path('Services/OpenCompat/OpenCompatIngestService.php'));
        $specDocuments = file_get_contents(app_path('Services/SpecV2/DocumentService.php'));
        $internalApiRoutes = file_get_contents(base_path('routes/internal_api.php'));
        $appSearchRoutes = file_get_contents(base_path('routes/internal_api/app_search.php'));
        $appIngestionRoutes = file_get_contents(base_path('routes/internal_api/app_ingestion.php'));
        $specRoutes = file_get_contents(base_path('routes/internal_api/spec_v2.php'));
        $operatorRoutes = file_get_contents(base_path('routes/internal_api/operator.php'));
        $queryExecution = file_get_contents(base_path('python_rag/application/workflows/query_execution.py'));
        $payloadSync = file_get_contents(app_path('Services/SpecV2/DocumentSearchPayloadSyncService.php'));

        $this->assertIsString($contract);
        $this->assertStringContainsString('Laravel owns actor resolution', $contract);
        $this->assertStringContainsString('Python is a retrieval engine only', $contract);
        $this->assertStringContainsString('Qdrant payload mutation is a write-path concern', $contract);
        $this->assertStringContainsString('App-facing internal APIs use application bearer tokens only', $contract);
        $this->assertStringContainsString('No legacy compatibility API routes are registered', $contract);

        $this->assertIsString($proxy);
        $this->assertStringContainsString('GatewaySearchFilterService', $proxy);
        $this->assertStringNotContainsString('PermissionGraphClient', $proxy);

        $this->assertIsString($retrieval);
        $this->assertStringContainsString('ApplicationReadPolicy', $retrieval);
        $this->assertStringContainsString('OpenCompatDocumentService', $retrieval);

        $this->assertIsString($compatDocumentService);
        $this->assertStringContainsString('DocumentBrowserService', $compatDocumentService);
        $this->assertStringNotContainsString('PipelineUploadInput', $compatDocumentService);

        $this->assertIsString($compatIngestService);
        $this->assertStringContainsString('PipelineUploadInput', $compatIngestService);
        $this->assertStringNotContainsString('LegacyDatasetService', $compatIngestService);

        $this->assertIsString($specDocuments);
        $this->assertStringContainsString('CorpusSyncService', $specDocuments);
        $this->assertStringContainsString('OpenCompatIngestService', $specDocuments);

        $this->assertIsString($internalApiRoutes);
        $this->assertStringNotContainsString('compatibility.php', $internalApiRoutes);
        $this->assertIsString($appSearchRoutes);
        $this->assertStringContainsString('auth:application-token', $appSearchRoutes);
        $this->assertStringNotContainsString('sanctum,oidc', $appSearchRoutes);
        $this->assertStringContainsString("Route::prefix('search')", $appSearchRoutes);
        $this->assertStringNotContainsString("Route::prefix('retrieve')", $appSearchRoutes);
        $this->assertStringNotContainsString("Route::post('/query'", $appSearchRoutes);
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

        $this->assertIsString($payloadSync);
        $this->assertStringContainsString('syncQdrantPayload', $payloadSync);
    }

    private function seedHeapCorpusAndGroup(
        string $heapId,
        string $tenantId,
        string $applicationId,
        string $corpusId,
        string $groupId,
        bool $protected = false,
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
}
