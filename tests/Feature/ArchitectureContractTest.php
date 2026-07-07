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

            return $this->payloadHasOnlyBridgeKeys($request->data())
                && $request->data()['limit'] === 5
                && $this->filterContains($filters, 'protected', true)
                && ($this->filterContains($filters, 'heap', 'heap-protected')
                    || $this->filterContainsDocumentId($filters, (string) $document->id));
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
        $searchContract = file_get_contents(base_path('docs/hawki-rag-v2-search-contract.md'));
        $proxy = file_get_contents(app_path('Http/Controllers/API/HawkiRagProxyController.php'));
        $retrieval = file_get_contents(app_path('Http/Controllers/API/OpenCompat/RetrievalController.php'));
        $compatService = file_get_contents(app_path('Services/OpenCompat/OpenCompatService.php'));
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
            'api/pipeline/tasks/start',
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
            app_path('Http/Controllers/SpecV2/HeapController.php'),
            app_path('Http/Requests/Document/ListDocumentsRequest.php'),
            app_path('Http/Requests/Pipeline/UploadPipelineFileRequest.php'),
            app_path('Http/Requests/Pipeline/StartPipelineTaskRequest.php'),
            app_path('Http/Requests/Pipeline/ListFailedPipelineJobsRequest.php'),
            app_path('Http/Resources/SpecV2/HeapResource.php'),
            app_path('Http/Resources/SpecV2/DocumentResource.php'),
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

    /**
     * @param array<string, mixed> $filter
     */
    private function filterContains(array $filter, string $key, mixed $value): bool
    {
        if (array_key_exists($key, $filter)) {
            $candidate = $filter[$key];

            if (is_array($candidate)) {
                return in_array($value, $candidate, true);
            }

            return $candidate === $value;
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
