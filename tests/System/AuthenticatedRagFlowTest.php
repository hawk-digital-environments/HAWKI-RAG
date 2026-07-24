<?php

declare(strict_types=1);

namespace Tests\System;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Vertical browser-query scenario.
 *
 * This test uses a persisted Sanctum token, the real /api/auth/session exchange,
 * the browser-principal middleware, the dataset-grant database lookup, the
 * query FormRequest, controller, and proxy service. The Python bridge and
 * Qdrant collection catalog are the faked boundaries; Qdrant queries, Neo4j,
 * and model providers are therefore not started or proven compatible here.
 * Database behavior runs through Laravel's migrations and Eloquent on the
 * isolated SQLite test connection, not live PostgreSQL.
 */
class AuthenticatedRagFlowTest extends SystemTestCase
{
    use RefreshDatabase;

    public function test_query_token_becomes_a_dataset_scoped_browser_session(): void
    {
        config()->set('config.admin_auth.bypass', false);
        config()->set('config.query_auth.development_bypass', false);

        $settingsPath = storage_path('framework/testing/system-authenticated-rag-settings.json');
        File::delete($settingsPath);
        config()->set('config.admin_settings_path', $settingsPath);

        $bridgeEndpoint = rtrim((string) config('config.hawki_rag_bridge_url'), '/').'/query';
        $this->fakeAvailableQdrantCollections(['hawki_system_authenticated'], [
            $bridgeEndpoint => Http::response([
                'ok' => true,
                'answer' => 'The authenticated session received a scoped answer.',
                'sources' => [],
            ]),
        ]);

        $user = $this->createSystemUser('system-browser-query');
        $dataset = $this->createReadyDataset(
            'system-authenticated',
            'System Authenticated Dataset',
            'hawki_system_authenticated',
            'graph_system_authenticated',
        );
        $this->grantQueryAccess($user, $dataset);
        $token = $user->createToken('system-browser-query', ['query'])->plainTextToken;

        $this->withHeader('Origin', rtrim((string) config('app.url'), '/'))
            ->withSession(['_token' => 'system-browser-csrf'])
            ->withToken($token)
            ->postJson('/api/auth/session', [], [
                'X-CSRF-TOKEN' => 'system-browser-csrf',
            ])
            ->assertOk()
            ->assertExactJson(['authenticated' => true]);

        $this->withHeader('Authorization', '')
            ->getJson('/api/query/datasets')
            ->assertOk()
            ->assertExactJson([
                'datasets' => [[
                    'dataset_id' => 'system-authenticated',
                    'name' => 'System Authenticated Dataset',
                ]],
            ]);

        $this->withHeader('Authorization', '')
            ->withHeader('X-CSRF-TOKEN', 'system-browser-csrf')
            ->postJson('/api/query', [
                'dataset_id' => 'system-authenticated',
                'query' => 'What can this authenticated browser session retrieve?',
                'top_k' => 4,
                'filters' => [
                    'source_type' => 'pdf',
                    'language' => 'en',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('answer', 'The authenticated session received a scoped answer.');

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) use ($bridgeEndpoint): bool {
            $payload = $request->data();

            return $request->method() === 'POST'
                && $request->url() === $bridgeEndpoint
                && ($payload['query'] ?? null) === 'What can this authenticated browser session retrieve?'
                && ($payload['top_k'] ?? null) === 4
                && ($payload['filters'] ?? null) === [
                    'source_type' => 'pdf',
                    'language' => 'en',
                ]
                && ($payload['authorized_scope'] ?? null) === [
                    'dataset_id' => 'system-authenticated',
                    'qdrant_collection' => 'hawki_system_authenticated',
                    'neo4j_namespace' => 'graph_system_authenticated',
                    'embedding_provider' => 'ollama',
                    'embedding_model' => 'bge-m3',
                    'graph_enabled' => true,
                ]
                && ! array_key_exists('dataset_id', $payload);
        });

        // A query session is deliberately not an admin session.
        $this->withHeader('Authorization', '')
            ->getJson('/api/settings/config')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Admin authentication required.']);
    }
}
