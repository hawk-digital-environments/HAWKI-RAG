<?php
declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class SwaggerDocumentationTest extends TestCase
{
    public function test_swagger_openapi_file_documents_canonical_v2_routes(): void
    {
        $spec = Yaml::parseFile(public_path('swagger/openapi.yaml'));

        $this->assertSame('3.0.3', $spec['openapi'] ?? null);
        $this->assertSame('HAWKI RAG V2', $spec['info']['title'] ?? null);

        $paths = $spec['paths'] ?? [];
        $this->assertArrayHasKey('/tenants', $paths);
        $this->assertArrayHasKey('/applications', $paths);
        $this->assertArrayHasKey('/heaps', $paths);
        $this->assertArrayHasKey('/heaps/{heapId}/documents', $paths);
        $this->assertArrayHasKey('/documents/{documentId}', $paths);
        $this->assertArrayHasKey('/search', $paths);
        $this->assertArrayHasKey('/search/chunks', $paths);
        $this->assertArrayHasKey('/search/chunks/grouped', $paths);
        $this->assertArrayHasKey('/auth/check', $paths);
        $this->assertArrayHasKey('/auth/groups', $paths);
        $this->assertArrayHasKey('/auth/groups/{groupId}', $paths);
        $this->assertArrayHasKey('/auth/groups/{groupId}/users', $paths);
        $this->assertArrayHasKey('/auth/users/by-identifier/heaps', $paths);
        $this->assertArrayHasKey('/auth/heaps/{heapId}', $paths);
        $this->assertArrayHasKey('/auth/documents/{documentId}', $paths);
        $this->assertArrayNotHasKey('/groups', $paths);
        $this->assertArrayNotHasKey('/groups/{groupId}', $paths);
        $this->assertArrayNotHasKey('/groups/{groupId}/users', $paths);
        $this->assertArrayNotHasKey('/auth/heaps/{heapId}/grants', $paths);
        $this->assertArrayNotHasKey('/auth/documents/{documentId}/grants', $paths);

        $multipart = $paths['/heaps/{heapId}/documents']['post']['requestBody']['content']['multipart/form-data']['schema'] ?? null;
        $this->assertSame('#/components/schemas/CreateDocumentFileRequest', $multipart['$ref'] ?? null);
        $this->assertSame('#/components/schemas/SearchRequest', $paths['/search']['post']['requestBody']['content']['application/json']['schema']['$ref'] ?? null);
        $this->assertSame('#/components/schemas/CanonicalFilterExpression', $spec['components']['schemas']['SearchRequest']['properties']['filters']['$ref'] ?? null);
        $this->assertArrayHasKey('limit', $spec['components']['schemas']['SearchRequest']['properties'] ?? []);
        $this->assertArrayNotHasKey('top_k', $spec['components']['schemas']['SearchRequest']['properties'] ?? []);
        $this->assertSame('array', $spec['components']['schemas']['CanonicalFilterLeaf']['type'] ?? null);
        $this->assertSame(2, $spec['components']['schemas']['CanonicalFilterLeaf']['minItems'] ?? null);
        $this->assertArrayHasKey('CanonicalFilterImplicitAnd', $spec['components']['schemas'] ?? []);
        $this->assertArrayNotHasKey('protected', $spec['components']['schemas']['CreateHeapRequest']['properties'] ?? []);
        $this->assertArrayNotHasKey('protected', $spec['components']['schemas']['UpdateHeapRequest']['properties'] ?? []);
        $this->assertSame(['discoverable', 'all'], $spec['components']['parameters']['VisibilityFilter']['schema']['enum'] ?? null);
        $this->assertArrayHasKey('204', $paths['/heaps/{heapId}']['delete']['responses'] ?? []);
        $this->assertArrayNotHasKey('200', $paths['/heaps/{heapId}']['delete']['responses'] ?? []);
        $this->assertSame('#/components/schemas/HeapDeleteFailureResponse', $paths['/heaps/{heapId}']['delete']['responses']['502']['content']['application/json']['schema']['$ref'] ?? null);
        $this->assertV2SchemaUsesSnakeCase($spec, 'Heap', ['heap_id', 'tenant_id', 'owner_app', 'document_count'], ['heapId', 'tenantId', 'ownerApp', 'documentCount']);
        $this->assertV2SchemaUsesSnakeCase($spec, 'Document', ['document_id', 'heap_id', 'corpus_id', 'source_url', 'source_type'], ['documentId', 'heapId', 'corpusId', 'sourceUrl', 'sourceType']);
        $this->assertV2SchemaUsesSnakeCase($spec, 'Corpus', ['reference_count', 'document_count', 'content_preview'], ['referenceCount', 'documentCount', 'contentPreview']);
        $this->assertV2SchemaUsesSnakeCase($spec, 'Group', ['tenant_id', 'owner_app', 'member_count', 'assigned_heaps'], ['tenantId', 'ownerApp', 'memberCount', 'assignedHeaps']);
        $this->assertV2SchemaUsesSnakeCase($spec, 'SearchResponse', ['query', 'count', 'results'], ['ok', 'hits', 'kg', 'retrieval']);
        $this->assertV2SchemaUsesSnakeCase($spec, 'SearchResult', ['document_id', 'content', 'metadata'], ['payload']);
        $this->assertArrayNotHasKey('GrantPayload', $spec['components']['schemas'] ?? []);
        $this->assertSame('#/components/schemas/HeapGrantPayload', $paths['/auth/heaps/{heapId}']['get']['responses']['200']['content']['application/json']['schema']['$ref'] ?? null);
        $this->assertArrayHasKey('201', $paths['/auth/heaps/{heapId}']['put']['responses'] ?? []);
        $this->assertSame('#/components/schemas/DocumentGrantPayload', $paths['/auth/documents/{documentId}']['get']['responses']['200']['content']['application/json']['schema']['$ref'] ?? null);
        $this->assertArrayHasKey('201', $paths['/auth/documents/{documentId}']['put']['responses'] ?? []);
        $this->assertAuthEndpointCoverage($paths);
        $this->assertSame('#/components/schemas/AccessCheckResponse', $paths['/auth/check']['get']['responses']['200']['content']['application/json']['schema']['$ref'] ?? null);
        $this->assertSame('#/components/schemas/HeapsByIdentifierResponse', $paths['/auth/users/by-identifier/heaps']['get']['responses']['200']['content']['application/json']['schema']['$ref'] ?? null);
        $this->assertSame('#/components/schemas/ReplaceDocumentGrantAssignmentsRequest', $paths['/auth/documents/{documentId}']['put']['requestBody']['content']['application/json']['schema']['$ref'] ?? null);
        $this->assertSame('#/components/schemas/UpdateDocumentGrantAssignmentsRequest', $paths['/auth/documents/{documentId}']['patch']['requestBody']['content']['application/json']['schema']['$ref'] ?? null);
        $this->assertV2SchemaUsesSnakeCase($spec, 'HeapGrantPayload', ['heap_id', 'protected', 'grants'], ['heapId', 'users', 'groups', 'count']);
        $this->assertV2SchemaUsesSnakeCase($spec, 'DocumentGrantPayload', ['document_id', 'grants'], ['documentId', 'users', 'groups', 'count']);
        $this->assertArrayHasKey('permitted', $spec['components']['schemas']['AccessCheckResponse']['properties'] ?? []);
        $this->assertArrayNotHasKey('allowed', $spec['components']['schemas']['AccessCheckResponse']['properties'] ?? []);
        $this->assertArrayNotHasKey('add', $spec['components']['schemas']['UpdateGrantAssignmentsRequest']['properties'] ?? []);
        $this->assertArrayNotHasKey('remove', $spec['components']['schemas']['UpdateGrantAssignmentsRequest']['properties'] ?? []);
        $this->assertStringContainsString('no-op', $paths['/auth/users/by-identifier/heaps']['get']['description'] ?? '');

        $securityScheme = $spec['components']['securitySchemes']['ApplicationBearerAuth'] ?? [];
        $this->assertSame('http', $securityScheme['type'] ?? null);
        $this->assertSame('bearer', $securityScheme['scheme'] ?? null);
    }

    /**
     * @param array<string, mixed> $paths
     */
    private function assertAuthEndpointCoverage(array $paths): void
    {
        $expected = [
            '/auth/check' => ['get'],
            '/auth/users/by-identifier/heaps' => ['get'],
            '/auth/groups' => ['get', 'post'],
            '/auth/groups/{groupId}' => ['get', 'delete'],
            '/auth/groups/{groupId}/users' => ['get', 'put', 'patch'],
            '/auth/heaps/{heapId}' => ['get', 'put', 'patch', 'delete'],
            '/auth/documents/{documentId}' => ['get', 'put', 'patch', 'delete'],
        ];

        foreach ($expected as $path => $methods) {
            $this->assertArrayHasKey($path, $paths);
            foreach ($methods as $method) {
                $this->assertArrayHasKey($method, $paths[$path], $path.' should document '.$method);
            }
        }
    }

    /**
     * @param array<string, mixed> $spec
     * @param list<string> $requiredKeys
     * @param list<string> $forbiddenKeys
     */
    private function assertV2SchemaUsesSnakeCase(array $spec, string $schema, array $requiredKeys, array $forbiddenKeys): void
    {
        $properties = $spec['components']['schemas'][$schema]['properties'] ?? [];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $properties, $schema.' should expose '.$key);
        }

        foreach ($forbiddenKeys as $key) {
            $this->assertArrayNotHasKey($key, $properties, $schema.' should not expose '.$key);
        }
    }
}
