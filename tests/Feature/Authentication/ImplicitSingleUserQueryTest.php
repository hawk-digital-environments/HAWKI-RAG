<?php

declare(strict_types=1);

namespace Tests\Feature\Authentication;

use App\Models\Dataset;
use App\Models\User;
use App\Services\Authorization\DatasetQueryAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ImplicitSingleUserQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_credential_free_query_resolves_the_sole_active_user_and_only_lists_query_ready_datasets(): void
    {
        $user = $this->createUser('sole-active');
        $ready = $this->createDataset('implicit-ready', 'Implicit Ready');
        $incomplete = $this->createDataset('implicit-incomplete', 'Implicit Incomplete', qdrantCollection: '');
        $missingCollection = $this->createDataset('implicit-missing-collection', 'Implicit Missing Collection');
        $inactive = $this->createDataset(
            'implicit-inactive',
            'Implicit Inactive',
            status: Dataset::STATUS_ARCHIVED,
        );
        $authorization = app(DatasetQueryAuthorizationService::class);
        $authorization->grantQueryAccess($user, $ready);
        $authorization->grantQueryAccess($user, $incomplete);
        $authorization->grantQueryAccess($user, $missingCollection);
        $authorization->grantQueryAccess($user, $inactive);
        $this->fakeAvailableQdrantCollections([(string) $ready->qdrant_collection]);

        $response = $this->getJson('/api/query/datasets')
            ->assertOk()
            ->assertExactJson([
                'datasets' => [[
                    'dataset_id' => 'implicit-ready',
                    'name' => 'Implicit Ready',
                ]],
            ]);

        $this->assertStringNotContainsString('qdrant_collection', $response->getContent());
        $this->assertStringNotContainsString('neo4j_namespace', $response->getContent());
        $this->assertDatabaseCount('dataset_grants', 4);
    }

    public function test_credential_free_query_dispatches_the_normal_server_derived_scope(): void
    {
        config()->set('config.query_auth.all_datasets_by_default', true);
        $this->createUser('implicit-scope');
        $dataset = $this->createDataset('implicit-scope', 'Implicit Scope');
        $bridgeEndpoint = rtrim((string) config('config.hawki_rag_bridge_url'), '/').'/query';
        Http::fake([
            $bridgeEndpoint => Http::response([
                'ok' => true,
                'answer' => 'Implicit single-user answer.',
            ]),
        ]);

        $this->postJson('/api/query', [
            'dataset_id' => $dataset->dataset_id,
            'query' => 'What may the sole user retrieve?',
        ])->assertOk()
            ->assertJsonPath('answer', 'Implicit single-user answer.');

        Http::assertSentCount(1);
        Http::assertSent(static function (Request $request) use ($bridgeEndpoint): bool {
            $payload = $request->data();

            return $request->url() === $bridgeEndpoint
                && ($payload['authorized_scope'] ?? null) === [
                    'dataset_id' => 'implicit-scope',
                    'qdrant_collection' => 'hawki_implicit-scope',
                    'neo4j_namespace' => 'graph_implicit-scope',
                    'embedding_provider' => 'ollama',
                    'embedding_model' => 'bge-m3',
                    'graph_enabled' => true,
                ]
                && ! array_key_exists('auth_context', $payload);
        });
    }

    public function test_credential_free_query_fails_with_a_stable_service_error_when_no_active_user_exists(): void
    {
        $this->createUser('removed-only', removed: true);

        $this->getJson('/api/query/datasets')
            ->assertStatus(503)
            ->assertExactJson([
                'message' => 'Query access requires exactly one active user.',
                'error' => 'single_user_query_principal_unavailable',
            ]);
    }

    public function test_credential_free_query_fails_with_the_same_service_error_for_multiple_active_users(): void
    {
        $this->createUser('first-active');
        $this->createUser('second-active');

        $this->getJson('/api/query/datasets')
            ->assertStatus(503)
            ->assertExactJson([
                'message' => 'Query access requires exactly one active user.',
                'error' => 'single_user_query_principal_unavailable',
            ]);
    }

    public function test_removed_users_do_not_make_the_sole_active_user_ambiguous(): void
    {
        $active = $this->createUser('active-with-removed');
        $this->createUser('removed-neighbor', removed: true);
        $dataset = $this->createDataset('active-with-removed', 'Active With Removed');
        app(DatasetQueryAuthorizationService::class)->grantQueryAccess($active, $dataset);
        $this->fakeAvailableQdrantCollections([(string) $dataset->qdrant_collection]);

        $this->getJson('/api/query/datasets')
            ->assertOk()
            ->assertJsonPath('datasets.0.dataset_id', $dataset->dataset_id);
    }

    public function test_invalid_bearer_credentials_never_fall_through_to_the_sole_active_user(): void
    {
        $this->createUser('implicit-invalid-bearer');

        $this->withToken('invalid-token')
            ->getJson('/api/query/datasets')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_other_invalid_authorization_credentials_never_fall_through_to_the_sole_active_user(): void
    {
        $this->createUser('implicit-invalid-authorization');

        $this->withHeader('Authorization', 'Basic invalid')
            ->getJson('/api/query/datasets')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_bearer_without_query_ability_never_falls_through_to_the_sole_active_user(): void
    {
        $user = $this->createUser('implicit-wrong-ability');
        $token = $user->createToken('wrong-ability', ['admin'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/query/datasets')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_removed_bearer_principal_never_falls_through_to_an_active_user(): void
    {
        $this->createUser('implicit-active-fallback');
        $removed = $this->createUser('explicit-removed', removed: true);
        $token = $removed->createToken('removed-query', ['query'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/query/datasets')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_valid_bearer_principal_takes_precedence_even_when_implicit_resolution_is_ambiguous(): void
    {
        $bearerUser = $this->createUser('explicit-bearer');
        $this->createUser('other-active');
        $dataset = $this->createDataset('explicit-bearer', 'Explicit Bearer');
        app(DatasetQueryAuthorizationService::class)->grantQueryAccess($bearerUser, $dataset);
        $this->fakeAvailableQdrantCollections([(string) $dataset->qdrant_collection]);
        $token = $bearerUser->createToken('query', ['query'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/query/datasets')
            ->assertOk()
            ->assertJsonPath('datasets.0.dataset_id', $dataset->dataset_id);
    }

    private function createUser(string $username, bool $removed = false): User
    {
        return User::query()->create([
            'username' => $username,
            'email' => $username.'@example.test',
            'ip' => '127.30.0.'.(User::query()->count() + 1),
            'isRemoved' => $removed,
        ]);
    }

    private function createDataset(
        string $datasetId,
        string $name,
        ?string $qdrantCollection = null,
        string $status = Dataset::STATUS_ACTIVE,
    ): Dataset {
        return Dataset::query()->create([
            'dataset_id' => $datasetId,
            'name' => $name,
            'description' => null,
            'status' => $status,
            'qdrant_collection' => $qdrantCollection ?? 'hawki_'.$datasetId,
            'neo4j_namespace' => 'graph_'.$datasetId,
            'embedding_provider' => 'ollama',
            'embedding_model' => 'bge-m3',
            'created_at' => now(),
        ]);
    }
}
