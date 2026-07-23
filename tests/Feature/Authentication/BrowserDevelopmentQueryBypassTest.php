<?php

declare(strict_types=1);

namespace Tests\Feature\Authentication;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Models\User;
use App\Services\Authorization\DatasetQueryAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class BrowserDevelopmentQueryBypassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('config.operator_auth.bypass', true);
        config()->set('config.operator_auth.bypass_environments', [app()->environment()]);
        config()->set('config.query_auth.development_bypass', true);
        config()->set('config.query_auth.development_bypass_environments', [app()->environment()]);
    }

    public function test_development_principal_unlocks_the_page_and_lists_only_explicit_ready_grants(): void
    {
        $user = $this->createUser('development-query-user');
        $ready = $this->createDataset('development-ready', 'Development Ready');
        $unready = $this->createDataset('development-unready', 'Development Unready', qdrantCollection: '');
        $this->createDataset('development-ungranted', 'Development Ungranted');
        $this->grant($user, $ready);
        $this->grant($user, $unready);
        $this->configureDevelopmentUser($user);
        $userCount = User::query()->count();
        $grantCount = DatasetGrant::query()->count();

        $this->withoutVite();
        $this->get('/hawki-rag-playground')
            ->assertOk()
            ->assertSee('"operatorAuthorized":true', false)
            ->assertSee('"queryAuthenticated":true', false);

        $this->get('/datasets')
            ->assertOk()
            ->assertSee('"operatorAuthorized":true', false)
            ->assertSee('"queryAuthenticated":true', false);

        $response = $this->getJson('/api/query/datasets')
            ->assertOk()
            ->assertExactJson([
                'datasets' => [[
                    'dataset_id' => 'development-ready',
                    'name' => 'Development Ready',
                ]],
            ]);

        $this->assertStringNotContainsString('qdrant_collection', $response->getContent());
        $this->assertStringNotContainsString('neo4j_namespace', $response->getContent());
        $this->assertSame($userCount, User::query()->count());
        $this->assertSame($grantCount, DatasetGrant::query()->count());
        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_development_principal_query_uses_the_normal_server_derived_scope(): void
    {
        $user = $this->createUser('development-query-scope');
        $dataset = $this->createDataset('development-scope', 'Development Scope');
        $this->grant($user, $dataset);
        $this->configureDevelopmentUser($user);
        Http::fake(['*' => Http::response(['ok' => true, 'answer' => 'Development answer.'])]);

        $this->withSession(['_token' => 'development-csrf'])
            ->postJson('/api/query', [
                'dataset_id' => $dataset->dataset_id,
                'query' => 'What is available in development?',
            ], [
                'X-CSRF-TOKEN' => 'development-csrf',
            ])
            ->assertOk()
            ->assertJsonPath('answer', 'Development answer.');

        Http::assertSent(function (Request $request): bool {
            return ($request->data()['authorized_scope'] ?? null) === [
                'dataset_id' => 'development-scope',
                'qdrant_collection' => 'hawki_development-scope',
                'neo4j_namespace' => 'graph_development-scope',
                'embedding_provider' => 'ollama',
                'embedding_model' => 'bge-m3',
                'graph_enabled' => true,
            ];
        });
    }

    public function test_development_bypass_is_fail_closed_when_disabled_misconfigured_or_removed(): void
    {
        $user = $this->createUser('development-query-denied');
        $this->configureDevelopmentUser($user);

        config()->set('config.query_auth.development_bypass', false);
        $this->getJson('/api/query/datasets')->assertUnauthorized();

        config()->set('config.query_auth.development_bypass', true);
        config()->set('config.query_auth.development_bypass_environments', ['production']);
        $this->getJson('/api/query/datasets')->assertUnauthorized();

        config()->set('config.query_auth.development_bypass_environments', [app()->environment()]);
        config()->set('config.query_auth.development_user_id', '999999');
        $this->getJson('/api/query/datasets')->assertUnauthorized();

        $user->isRemoved = true;
        $user->save();
        $this->configureDevelopmentUser($user);
        $this->getJson('/api/query/datasets')->assertUnauthorized();
    }

    public function test_invalid_bearer_token_does_not_fall_through_to_development_access(): void
    {
        $user = $this->createUser('development-invalid-bearer');
        $dataset = $this->createDataset('development-invalid-bearer-dataset', 'Development Invalid Bearer Dataset');
        $this->grant($user, $dataset);
        $this->configureDevelopmentUser($user);

        $this->withToken('invalid-token')
            ->getJson('/api/query/datasets')
            ->assertUnauthorized();
    }

    public function test_development_query_bypass_does_not_grant_operator_authorization(): void
    {
        $user = $this->createUser('development-not-operator');
        $dataset = $this->createDataset('development-not-operator-dataset', 'Development Not Operator Dataset');
        $this->grant($user, $dataset);
        $this->configureDevelopmentUser($user);
        config()->set('config.operator_auth.bypass', false);

        $this->withoutVite();
        $this->get('/hawki-rag-playground')
            ->assertOk()
            ->assertSee('"operatorAuthorized":false', false)
            ->assertSee('"queryAuthenticated":true', false);

        $this->getJson('/api/query/datasets')
            ->assertOk()
            ->assertJsonPath('datasets.0.dataset_id', 'development-not-operator-dataset');
        $this->getJson('/api/settings/config')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Operator authentication required.');
    }

    public function test_development_bypass_is_hard_denied_in_production(): void
    {
        $user = $this->createUser('development-production-denied');
        $dataset = $this->createDataset('development-production', 'Development Production');
        $this->grant($user, $dataset);
        $this->configureDevelopmentUser($user);
        config()->set('config.query_auth.development_bypass_environments', ['production']);
        $this->app->detectEnvironment(static fn (): string => 'production');
        Http::fake();

        $this->getJson('/api/query/datasets')->assertUnauthorized();
        $this->withSession(['_token' => 'production-csrf'])
            ->postJson('/api/query', [
                'dataset_id' => $dataset->dataset_id,
                'query' => 'Production must not use the development principal.',
            ], [
                'X-CSRF-TOKEN' => 'production-csrf',
            ])
            ->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_real_principal_takes_precedence_over_the_development_principal(): void
    {
        $developmentUser = $this->createUser('development-principal');
        $realUser = $this->createUser('real-principal');
        $developmentDataset = $this->createDataset('development-only', 'Development Only');
        $realDataset = $this->createDataset('real-only', 'Real Only');
        $this->grant($developmentUser, $developmentDataset);
        $this->grant($realUser, $realDataset);
        $this->configureDevelopmentUser($developmentUser);

        $this->actingAs($realUser)
            ->getJson('/api/query/datasets')
            ->assertOk()
            ->assertExactJson([
                'datasets' => [[
                    'dataset_id' => 'real-only',
                    'name' => 'Real Only',
                ]],
            ]);
    }

    public function test_removed_real_principal_cannot_fall_through_to_development_access(): void
    {
        $developmentUser = $this->createUser('development-fallback');
        $removedUser = $this->createUser('removed-real', removed: true);
        $dataset = $this->createDataset('development-fallback-dataset', 'Development Fallback Dataset');
        $this->grant($developmentUser, $dataset);
        $this->configureDevelopmentUser($developmentUser);

        $this->actingAs($removedUser)
            ->getJson('/api/query/datasets')
            ->assertUnauthorized();
    }

    public function test_development_bypass_uses_only_the_canonical_query_api(): void
    {
        $user = $this->createUser('development-browser-only');
        $dataset = $this->createDataset('development-browser-only-dataset', 'Development Browser Only');
        $this->grant($user, $dataset);
        $this->configureDevelopmentUser($user);

        $this->getJson('/api/query/datasets')
            ->assertOk()
            ->assertJsonPath('datasets.0.dataset_id', $dataset->dataset_id);

        $this->getJson('/query/datasets')->assertNotFound();
        $this->postJson('/query', [
            'dataset_id' => $dataset->dataset_id,
            'query' => 'Legacy browser API routes stay removed.',
        ])->assertNotFound();
    }

    private function configureDevelopmentUser(User $user): void
    {
        config()->set('config.query_auth.development_user_id', (string) $user->id);
    }

    private function createUser(string $name, bool $removed = false): User
    {
        $ipSuffix = User::query()->count() + 1;

        return User::query()->create([
            'username' => $name,
            'email' => $name.'@example.test',
            'ip' => '127.10.0.'.$ipSuffix,
            'isRemoved' => $removed,
        ]);
    }

    private function createDataset(
        string $datasetId,
        string $name,
        ?string $qdrantCollection = null,
    ): Dataset {
        return Dataset::query()->create([
            'dataset_id' => $datasetId,
            'name' => $name,
            'description' => null,
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => $qdrantCollection ?? 'hawki_'.$datasetId,
            'neo4j_namespace' => 'graph_'.$datasetId,
            'created_at' => now(),
        ]);
    }

    private function grant(User $user, Dataset $dataset): void
    {
        app(DatasetQueryAuthorizationService::class)->grantQueryAccess($user, $dataset);
    }
}
