<?php

declare(strict_types=1);

namespace Tests\System;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Vertical Laravel authorization-isolation scenario for two datasets.
 *
 * Sanctum, abilities middleware, persisted grants, uniform 404 handling, and
 * construction of the trusted authorized_scope are real. The Python request
 * is intercepted at the HTTP boundary, so this test proves that Laravel never
 * dispatches the denied dataset; live Qdrant payload filters and Neo4j Cypher
 * isolation remain the responsibility of the Python integration suite. The
 * grant queries use Laravel's isolated SQLite test database rather than live
 * PostgreSQL.
 */
class TwoDatasetIsolationTest extends SystemTestCase
{
    use RefreshDatabase;

    public function test_query_principal_can_dispatch_only_its_granted_dataset_scope(): void
    {
        $settingsPath = storage_path('framework/testing/system-two-dataset-settings.json');
        File::delete($settingsPath);
        config()->set('config.operator_settings_path', $settingsPath);

        $bridgeEndpoint = rtrim((string) config('config.hawki_rag_bridge_url'), '/').'/query';
        Http::fake([
            $bridgeEndpoint => Http::response([
                'ok' => true,
                'answer' => 'Only dataset alpha was dispatched.',
            ]),
        ]);

        $user = $this->createSystemUser('system-two-dataset-user');
        $allowed = $this->createReadyDataset(
            'system-alpha',
            'System Alpha',
            'hawki_system_alpha',
            'graph_system_alpha',
        );
        $this->createReadyDataset(
            'system-beta',
            'System Beta',
            'hawki_system_beta',
            'graph_system_beta',
        );
        $this->grantQueryAccess($user, $allowed);
        $token = $user->createToken('system-two-dataset-query', ['query'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/query/datasets')
            ->assertOk()
            ->assertExactJson([
                'datasets' => [[
                    'dataset_id' => 'system-alpha',
                    'name' => 'System Alpha',
                ]],
            ]);

        $this->withToken($token)
            ->postJson('/api/query', [
                'dataset_id' => 'system-alpha',
                'query' => 'Return evidence from alpha.',
            ])
            ->assertOk()
            ->assertJsonPath('answer', 'Only dataset alpha was dispatched.');

        $this->withToken($token)
            ->postJson('/api/query', [
                'dataset_id' => 'system-beta',
                'query' => 'Attempt to retrieve beta.',
            ])
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'The requested dataset is not available.',
                'error' => 'dataset_not_found',
            ]);

        $this->withToken($token)
            ->postJson('/api/query', [
                'dataset_id' => 'system-does-not-exist',
                'query' => 'Attempt to enumerate an unknown dataset.',
            ])
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'The requested dataset is not available.',
                'error' => 'dataset_not_found',
            ]);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($bridgeEndpoint): bool {
            $payload = $request->data();

            return $request->url() === $bridgeEndpoint
                && ($payload['authorized_scope'] ?? null) === [
                    'dataset_id' => 'system-alpha',
                    'qdrant_collection' => 'hawki_system_alpha',
                    'neo4j_namespace' => 'graph_system_alpha',
                    'embedding_provider' => 'ollama',
                    'embedding_model' => 'bge-m3',
                    'graph_enabled' => true,
                ]
                && ! array_key_exists('dataset_id', $payload)
                && ! str_contains(json_encode($payload, JSON_THROW_ON_ERROR), 'system-beta');
        });
    }
}
