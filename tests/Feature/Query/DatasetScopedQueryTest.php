<?php

declare(strict_types=1);

namespace Tests\Feature\Query;

use App\Models\Dataset;
use App\Models\User;
use App\Services\Authorization\DatasetQueryAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DatasetScopedQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_query_uses_only_server_derived_scope_and_forwards_safe_filters(): void
    {
        $user = $this->actingAsApiUser();
        $dataset = $this->createDataset('authorized', 'Authorized Dataset');
        $this->grant($user, $dataset);
        Http::fake([
            '*' => Http::response(['ok' => true, 'answer' => 'Scoped answer.']),
        ]);

        $this->postJson('/api/query', [
            'dataset_id' => 'authorized',
            'query' => 'What is in this dataset?',
            'top_k' => 7,
            'filters' => [
                'source_type' => 'pdf',
                'language' => 'en',
            ],
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('answer', 'Scoped answer.');

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === rtrim((string) config('config.hawki_rag_bridge_url'), '/').'/query'
                && ($payload['query'] ?? null) === 'What is in this dataset?'
                && ($payload['top_k'] ?? null) === 7
                && ($payload['provider'] ?? null) === 'ollama'
                && ($payload['chat_model'] ?? null) === 'llama3.1:8b'
                && ($payload['vision_model'] ?? null) === 'qwen2.5vl:7b'
                && ($payload['filters'] ?? null) === [
                    'source_type' => 'pdf',
                    'language' => 'en',
                ]
                && ($payload['authorized_scope'] ?? null) === [
                    'dataset_id' => 'authorized',
                    'qdrant_collection' => 'hawki_authorized',
                    'neo4j_namespace' => 'graph_authorized',
                    'embedding_provider' => 'ollama',
                    'embedding_model' => 'bge-m3',
                    'graph_enabled' => true,
                ]
                && ! array_key_exists('dataset_id', $payload);
        });
    }

    public function test_authorized_query_uses_the_server_selected_litellm_provider(): void
    {
        $settingsPath = storage_path('framework/testing/dataset-query-litellm.json');
        File::delete($settingsPath);
        File::put($settingsPath, json_encode([
            'models' => [
                'provider' => 'litellm',
                'graph_model' => 'hawki-chat',
                'embedding_model' => 'hawki-embedding',
            ],
        ], JSON_THROW_ON_ERROR));
        config()->set('config.operator_settings_path', $settingsPath);

        $user = $this->actingAsApiUser();
        $dataset = $this->createDataset(
            'litellm-query',
            'LiteLLM Query Dataset',
            embeddingProvider: 'litellm',
            embeddingModel: 'hawki-embedding',
        );
        $this->grant($user, $dataset);
        Http::fake([
            '*' => Http::response(['ok' => true, 'answer' => 'LiteLLM answer.']),
        ]);

        try {
            $this->postJson('/api/query', [
                'dataset_id' => $dataset->dataset_id,
                'query' => 'Use the selected provider.',
            ])->assertOk();

            Http::assertSent(static fn (Request $request): bool => ($request->data()['provider'] ?? null) === 'litellm');
        } finally {
            File::delete($settingsPath);
        }
    }

    public function test_unknown_unauthorized_and_inactive_datasets_have_the_same_not_found_response(): void
    {
        $user = $this->actingAsApiUser();
        $unauthorized = $this->createDataset('unauthorized', 'Unauthorized Dataset');
        $inactive = $this->createDataset('inactive', 'Inactive Dataset', Dataset::STATUS_ARCHIVED);
        $this->grant($user, $inactive);
        Http::fake();

        $unknownResponse = $this->postJson('/api/query', [
            'dataset_id' => 'unknown',
            'query' => 'Query',
        ]);
        $unauthorizedResponse = $this->postJson('/api/query', [
            'dataset_id' => $unauthorized->dataset_id,
            'query' => 'Query',
        ]);
        $inactiveResponse = $this->postJson('/api/query', [
            'dataset_id' => $inactive->dataset_id,
            'query' => 'Query',
        ]);

        foreach ([$unknownResponse, $unauthorizedResponse, $inactiveResponse] as $response) {
            $response->assertNotFound()->assertExactJson([
                'message' => 'The requested dataset is not available.',
                'error' => 'dataset_not_found',
            ]);
        }

        $this->assertSame($unknownResponse->json(), $unauthorizedResponse->json());
        $this->assertSame($unknownResponse->json(), $inactiveResponse->json());
        Http::assertNothingSent();
    }

    public function test_authorized_dataset_without_complete_storage_targets_fails_closed(): void
    {
        $user = $this->actingAsApiUser();
        $dataset = $this->createDataset('not-ready', 'Not Ready', qdrantCollection: '');
        $this->grant($user, $dataset);
        Http::fake();

        $this->postJson('/api/query', [
            'dataset_id' => $dataset->dataset_id,
            'query' => 'Query',
        ])->assertStatus(409)
            ->assertExactJson([
                'message' => 'The requested dataset is not ready for querying.',
                'error' => 'dataset_not_ready',
            ]);

        Http::assertNothingSent();
    }

    public function test_python_dataset_not_ready_response_is_forwarded_with_the_stable_contract(): void
    {
        $user = $this->actingAsApiUser();
        $dataset = $this->createDataset('missing-collection', 'Missing Collection');
        $this->grant($user, $dataset);
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'type' => 'HTTPException',
                    'status' => 503,
                    'message' => 'The authorized dataset storage is not ready.',
                    'path' => '/query',
                    'request_id' => 'rag-request-123',
                    'code' => 'dataset_not_ready',
                ],
            ], 503),
        ]);

        $this->postJson('/api/query', [
            'dataset_id' => $dataset->dataset_id,
            'query' => 'Query',
        ])->assertStatus(503)
            ->assertExactJson([
                'error' => [
                    'type' => 'HTTPException',
                    'status' => 503,
                    'message' => 'The authorized dataset storage is not ready.',
                    'path' => '/query',
                    'request_id' => 'rag-request-123',
                    'code' => 'dataset_not_ready',
                ],
            ]);

        Http::assertSentCount(1);
    }

    public function test_public_query_cannot_supply_storage_scope_or_reserved_filter_keys(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/query', [
            'dataset_id' => 'authorized',
            'query' => 'Query',
            'collection' => 'another_collection',
            'authorized_scope' => ['dataset_id' => 'another-dataset'],
            'neo4j_namespace' => 'another-graph',
            'graph_enabled' => false,
            'provider' => 'ollama',
            'chat_model' => 'arbitrary-paid-model',
            'embedding_model' => 'different-vector-space',
            'vision_model' => 'arbitrary-vision-model',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'collection',
                'authorized_scope',
                'neo4j_namespace',
                'graph_enabled',
                'provider',
                'chat_model',
                'embedding_model',
                'vision_model',
            ]);

        $this->postJson('/api/query', [
            'dataset_id' => 'authorized',
            'query' => 'Query',
            'filters' => [
                'datasetId' => 'another-dataset',
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('filters');
    }

    public function test_public_query_rejects_nested_and_non_finite_filter_values(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/query', [
            'dataset_id' => 'authorized',
            'query' => 'Query',
            'filters' => [
                'metadata' => ['language' => 'en'],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('filters');

        $this->call('POST', '/api/query', [
            'dataset_id' => 'authorized',
            'query' => 'Query',
            'filters' => [
                'score' => INF,
            ],
        ], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('filters');
    }

    public function test_authorized_dataset_selector_exposes_only_ready_dataset_identity(): void
    {
        $user = $this->actingAsApiUser();
        $ready = $this->createDataset('ready', 'Ready Dataset');
        $unready = $this->createDataset('unready', 'Unready Dataset', qdrantCollection: '');
        $inactive = $this->createDataset('inactive-list', 'Inactive Dataset', Dataset::STATUS_ARCHIVED);
        $this->createDataset('not-granted', 'Not Granted Dataset');
        $this->grant($user, $ready);
        $this->grant($user, $unready);
        $this->grant($user, $inactive);

        $response = $this->getJson('/api/query/datasets')
            ->assertOk()
            ->assertExactJson([
                'datasets' => [[
                    'dataset_id' => 'ready',
                    'name' => 'Ready Dataset',
                ]],
            ]);

        $this->assertStringNotContainsString('qdrant_collection', $response->getContent());
        $this->assertStringNotContainsString('neo4j_namespace', $response->getContent());
    }

    public function test_dataset_grant_command_provisions_query_access_without_broad_backfill(): void
    {
        $user = User::query()->create([
            'username' => 'grant-user',
            'email' => 'grant-user@example.test',
            'ip' => '127.0.0.210',
        ]);
        $dataset = $this->createDataset('grant-target', 'Grant Target');
        $otherDataset = $this->createDataset('not-granted-target', 'Not Granted Target');

        $this->artisan('dataset:grant-query', [
            'dataset_id' => $dataset->dataset_id,
            'user_id' => (string) $user->id,
        ])->assertSuccessful();

        $this->assertDatabaseHas('dataset_grants', [
            'dataset_id' => $dataset->dataset_id,
            'principal_type' => 'user',
            'principal_id' => (string) $user->id,
            'permission' => 'query',
        ]);
        $this->assertDatabaseMissing('dataset_grants', [
            'dataset_id' => $otherDataset->dataset_id,
            'principal_id' => (string) $user->id,
        ]);
    }

    private function grant(User $user, Dataset $dataset): void
    {
        app(DatasetQueryAuthorizationService::class)->grantQueryAccess($user, $dataset);
    }

    private function createDataset(
        string $datasetId,
        string $name,
        string $status = Dataset::STATUS_ACTIVE,
        ?string $qdrantCollection = null,
        ?string $neo4jNamespace = null,
        string $embeddingProvider = 'ollama',
        string $embeddingModel = 'bge-m3',
    ): Dataset {
        return Dataset::query()->create([
            'dataset_id' => $datasetId,
            'name' => $name,
            'description' => null,
            'status' => $status,
            'qdrant_collection' => $qdrantCollection ?? 'hawki_'.$datasetId,
            'neo4j_namespace' => $neo4jNamespace ?? 'graph_'.$datasetId,
            'embedding_provider' => $embeddingProvider,
            'embedding_model' => $embeddingModel,
            'created_at' => now(),
        ]);
    }
}
