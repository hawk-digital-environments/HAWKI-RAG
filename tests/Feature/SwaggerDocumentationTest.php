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

        $securityScheme = $spec['components']['securitySchemes']['ApplicationBearerAuth'] ?? [];
        $this->assertSame('http', $securityScheme['type'] ?? null);
        $this->assertSame('bearer', $securityScheme['scheme'] ?? null);
    }
}
