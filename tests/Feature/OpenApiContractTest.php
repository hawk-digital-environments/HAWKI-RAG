<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Tests\TestCase;

class OpenApiContractTest extends TestCase
{
    public function test_openapi_operations_match_registered_api_routes(): void
    {
        $documented = array_keys($this->openApiOperations());
        $registered = $this->registeredApiOperations();

        sort($documented);
        sort($registered);

        $this->assertSame(
            $registered,
            $documented,
            'public/swagger/openapi.yaml must document every registered /api method/path exactly once and contain no obsolete operations.',
        );
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
    private function registeredApiOperations(): array
    {
        $operations = [];

        /** @var Route $route */
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/')) {
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
