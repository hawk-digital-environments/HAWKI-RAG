<?php

declare(strict_types=1);

namespace Tests\Feature\ApiContract;

use Illuminate\Routing\Route;
use Tests\TestCase;

class OpenApiContractTest extends TestCase
{
    public function test_swagger_runtime_assets_are_packaged_for_docker(): void
    {
        $this->assertFileExists(public_path('swagger/index.html'));
        $this->assertFileExists(public_path('swagger/openapi.yaml'));

        $dockerIgnoreLines = file(
            base_path('.dockerignore'),
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES,
        );
        $this->assertIsArray($dockerIgnoreLines);
        $this->assertNotContains(
            'public/swagger/',
            array_map('trim', $dockerIgnoreLines),
            'Swagger runtime assets must not be excluded from the Laravel Docker image.',
        );
    }

    public function test_swagger_urls_are_relative_to_the_deployment_path(): void
    {
        $index = file_get_contents(public_path('swagger/index.html'));
        $this->assertIsString($index);
        $this->assertStringContainsString('window.location.pathname', $index);
        $this->assertStringNotContainsString('new URL("/swagger/openapi.yaml"', $index);
        $this->assertStringContainsString('- url: ../api', $this->openApiContents());
    }

    public function test_openapi_operations_match_registered_api_routes(): void
    {
        $documented = array_keys($this->openApiOperations());
        $registered = $this->registeredPublicApiOperations();

        sort($documented);
        sort($registered);

        $this->assertSame(
            $registered,
            $documented,
            'public/swagger/openapi.yaml must document every registered public /api method/path exactly once and contain no obsolete operations.',
        );
    }

    public function test_routes_explicitly_hidden_from_openapi_are_not_published(): void
    {
        $documented = array_keys($this->openApiOperations());

        foreach ($this->registeredHiddenApiOperations() as $operation) {
            $this->assertNotContains(
                $operation,
                $documented,
                "{$operation} is marked openapi=false and must not appear in the published contract.",
            );
        }
    }

    public function test_every_openapi_operation_explains_purpose_and_authentication(): void
    {
        foreach ($this->openApiOperations() as $operation => $block) {
            $this->assertMatchesRegularExpression(
                '/^      summary:\s+\S.+$/m',
                $block,
                "{$operation} must have a useful summary.",
            );
            $this->assertMatchesRegularExpression(
                '/^      description:\s*(?:\||\S.+)$/m',
                $block,
                "{$operation} must have a useful description.",
            );
            $this->assertMatchesRegularExpression(
                '/^      security:\s*$/m',
                $block,
                "{$operation} must declare its authentication contract explicitly.",
            );
        }
    }

    public function test_json_contracts_use_named_component_schemas(): void
    {
        $contents = $this->openApiContents();
        preg_match_all(
            '/application\/json:\R[ \t]+schema:\R[ \t]+([^\r\n]+)/',
            $contents,
            $matches,
        );

        $this->assertNotEmpty($matches[1], 'The OpenAPI document should contain JSON contracts.');
        foreach ($matches[1] as $schemaLine) {
            $this->assertStringStartsWith(
                '$ref: "#/components/schemas/',
                trim((string) $schemaLine),
                'JSON request and response bodies must use named component schemas instead of bare object placeholders.',
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function openApiOperations(): array
    {
        $operations = [];
        $path = null;
        $operation = null;
        $insidePaths = false;

        foreach (preg_split('/\R/', $this->openApiContents()) ?: [] as $line) {
            if ($line === 'paths:') {
                $insidePaths = true;

                continue;
            }

            if (! $insidePaths) {
                continue;
            }

            if ($line === 'components:') {
                break;
            }

            if (preg_match('/^  (\/[^:]+):\s*$/', $line, $matches) === 1) {
                $path = $matches[1];
                $operation = null;

                continue;
            }

            if ($path !== null && preg_match('/^    (get|post|put|patch|delete):\s*$/', $line, $matches) === 1) {
                $operation = $this->operationKey($matches[1], $path);
                $operations[$operation] = '';

                continue;
            }

            if ($operation !== null) {
                $operations[$operation] .= $line."\n";
            }
        }

        return $operations;
    }

    /**
     * @return list<string>
     */
    private function registeredPublicApiOperations(): array
    {
        return $this->registeredApiOperations(hidden: false);
    }

    /**
     * @return list<string>
     */
    private function registeredHiddenApiOperations(): array
    {
        return $this->registeredApiOperations(hidden: true);
    }

    /**
     * @return list<string>
     */
    private function registeredApiOperations(bool $hidden): array
    {
        $operations = [];

        /** @var Route $route */
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            $hiddenFromOpenApi = ($route->defaults['openapi'] ?? true) === false;
            if ($hiddenFromOpenApi !== $hidden) {
                continue;
            }

            $path = '/'.substr($uri, 4);
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $operations[] = $this->operationKey($method, $path);
            }
        }

        return array_values(array_unique($operations));
    }

    private function operationKey(string $method, string $path): string
    {
        $normalizedPath = preg_replace('/\{[^}]+\}/', '{parameter}', $path) ?? $path;

        return strtoupper($method).' '.$normalizedPath;
    }

    private function openApiContents(): string
    {
        $contents = file_get_contents(base_path('public/swagger/openapi.yaml'));
        $this->assertIsString($contents, 'public/swagger/openapi.yaml must be readable.');

        return $contents;
    }
}
