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
        $this->assertArrayNotHasKey('GrantPayload', $spec['components']['schemas'] ?? []);
        $this->assertSame('#/components/schemas/HeapGrantPayload', $paths['/auth/heaps/{heapId}']['get']['responses']['200']['content']['application/json']['schema']['$ref'] ?? null);
        $this->assertArrayHasKey('201', $paths['/auth/heaps/{heapId}']['put']['responses'] ?? []);
        $this->assertSame('#/components/schemas/DocumentGrantPayload', $paths['/auth/documents/{documentId}']['get']['responses']['200']['content']['application/json']['schema']['$ref'] ?? null);
        $this->assertArrayHasKey('201', $paths['/auth/documents/{documentId}']['put']['responses'] ?? []);

        $securityScheme = $spec['components']['securitySchemes']['ApplicationBearerAuth'] ?? [];
        $this->assertSame('http', $securityScheme['type'] ?? null);
        $this->assertSame('bearer', $securityScheme['scheme'] ?? null);
    }
}
