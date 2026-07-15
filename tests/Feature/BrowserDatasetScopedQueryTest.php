<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\User;
use App\Services\Authorization\DatasetQueryAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrowserDatasetScopedQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('config.operator_auth.bypass', false);
    }

    public function test_browser_bearer_principal_can_list_only_granted_ready_datasets(): void
    {
        $user = $this->createUser('browser-bearer');
        $ready = $this->createDataset('browser-ready', 'Browser Ready');
        $unready = $this->createDataset('browser-unready', 'Browser Unready', qdrantCollection: '');
        $this->createDataset('browser-not-granted', 'Browser Not Granted');
        $this->grant($user, $ready);
        $this->grant($user, $unready);

        $response = $this->withToken($user->createToken('browser-test')->plainTextToken)
            ->getJson('/query/datasets')
            ->assertOk()
            ->assertExactJson([
                'datasets' => [[
                    'dataset_id' => 'browser-ready',
                    'name' => 'Browser Ready',
                ]],
            ]);

        $this->assertStringNotContainsString('qdrant_collection', $response->getContent());
        $this->assertStringNotContainsString('neo4j_namespace', $response->getContent());
    }

    public function test_browser_session_principal_reaches_the_scoped_query_form_request(): void
    {
        $user = $this->createUser('browser-session');
        $dataset = $this->createDataset('browser-session-dataset', 'Browser Session Dataset');
        $this->grant($user, $dataset);
        Http::fake([
            '*' => Http::response(['ok' => true, 'answer' => 'Session-scoped answer.']),
        ]);

        $this->actingAs($user)
            ->withSession(['_token' => 'browser-csrf-token'])
            ->postJson('/query', [
                'dataset_id' => $dataset->dataset_id,
                'query' => 'What may this session user read?',
            ], [
                'X-CSRF-TOKEN' => 'browser-csrf-token',
            ])
            ->assertOk()
            ->assertJsonPath('answer', 'Session-scoped answer.');

        Http::assertSent(function (Request $request): bool {
            return ($request->data()['authorized_scope'] ?? null) === [
                'dataset_id' => 'browser-session-dataset',
                'qdrant_collection' => 'hawki_browser-session-dataset',
                'neo4j_namespace' => 'graph_browser-session-dataset',
                'embedding_model' => 'hawki-ollama-embedding',
                'graph_enabled' => true,
            ];
        });
    }

    public function test_scoped_browser_routes_deny_requests_without_a_real_principal(): void
    {
        $this->get('/query/datasets')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->getJson('/query/datasets')
            ->assertUnauthorized();

        config()->set('config.operator_auth.bypass', true);
        config()->set('config.operator_auth.bypass_environments', [app()->environment()]);

        $this->getJson('/query/datasets')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->withSession(['_token' => 'browser-csrf-token'])
            ->postJson('/query', [
                'dataset_id' => 'browser-ready',
                'query' => 'Bypass without a principal must fail.',
            ], [
                'X-CSRF-TOKEN' => 'browser-csrf-token',
            ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    private function createUser(string $name): User
    {
        return User::query()->create([
            'username' => $name,
            'email' => $name.'@example.test',
            'ip' => '127.0.0.1',
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
