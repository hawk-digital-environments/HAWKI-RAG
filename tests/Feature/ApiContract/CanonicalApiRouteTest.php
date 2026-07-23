<?php

declare(strict_types=1);

namespace Tests\Feature\ApiContract;

use Illuminate\Routing\Route;
use Tests\TestCase;

class CanonicalApiRouteTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const LEGACY_JSON_ROUTE_URIS = [
        'auth/session',
        'query',
        'query/datasets',
        'settings/config',
        'datasets/data',
        'datasets/data/{datasetId}',
        'datasets/data/{datasetId}/storage',
        'documents/data',
        'documents/data/{documentId}',
        'documents/uploads/download',
        'scraper/jobs',
        'scraper/jobs/{jobId}/cancel',
        'scraper/jobs/{jobId}/pause',
        'scraper/jobs/{jobId}/resume',
        'scraper/status/{jobId}',
        'scraper/tasks',
        'scraper/tasks/start',
        'pipeline/status/{jobId}',
        'pipeline/tasks',
        'pipeline/tasks/start',
        'pipeline/tasks/{taskId}',
        'pipeline/tasks/{taskId}/cancel',
        'pipeline/tasks/{taskId}/events',
        'pipeline/tasks/{taskId}/failed-jobs',
        'pipeline/tasks/{taskId}/jobs',
        'pipeline/tasks/{taskId}/retry',
        'pipeline/tasks/{taskId}/retry-failed-jobs',
        'pipeline/tasks/{taskId}/stages/{stage}/logs',
        'pipeline/tasks/{taskId}/stages/{stage}/logs/download',
        'pipeline/controller/files',
        'pipeline/recovery/failed-jobs',
        'pipeline/recovery/jobs/retry-selected',
        'pipeline/recovery/jobs/{jobId}/retry',
        'pipeline/recovery/retry-all',
        'pipeline/recovery/tasks/{taskId}/retry-failed',
        'pipeline/recovery/datasets/{datasetId}/retry-failed',
        'pipeline/health',
        'health/system-gate',
        'rag/health',
        'rag/monitor',
        'rag/stats',
        'rag/qdrant/collections/{collection}',
        'rag/neo4j/clear',
        'rag/neo4j/graph/clear-view',
        'rag/neo4j/graph/expand',
        'rag/neo4j/graph/node',
        'rag/neo4j/graph/overview',
        'rag/neo4j/graph/search',
        'rag/neo4j/graph/semantic-search',
        'rag/neo4j/graph/snapshots',
        'rag/neo4j/graph/snapshots/{id}',
    ];

    public function test_legacy_non_api_json_and_action_routes_are_not_registered(): void
    {
        $registeredUris = array_map(
            static fn (Route $route): string => $route->uri(),
            $this->app['router']->getRoutes()->getRoutes(),
        );

        foreach (self::LEGACY_JSON_ROUTE_URIS as $legacyUri) {
            $this->assertNotContains(
                $legacyUri,
                $registeredUris,
                "Legacy route {$legacyUri} must not be registered outside the canonical /api surface.",
            );
        }
    }

    public function test_each_controller_action_and_http_method_is_registered_once(): void
    {
        /** @var array<string, list<string>> $registrations */
        $registrations = [];

        /** @var Route $route */
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $action = $route->getActionName();
            if (! str_starts_with($action, 'App\\Http\\Controllers\\')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $registrations[$method.' '.$action][] = $route->uri();
            }
        }

        $duplicates = array_filter(
            $registrations,
            static fn (array $uris): bool => count($uris) > 1,
        );

        $this->assertSame(
            [],
            $duplicates,
            'A controller action and HTTP method must have one canonical route; duplicate registrations drift in middleware and behavior.',
        );
    }
}
