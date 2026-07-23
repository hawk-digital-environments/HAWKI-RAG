<?php

declare(strict_types=1);

namespace Tests\Feature\Authentication;

use App\Models\Dataset;
use App\Models\User;
use App\Services\Authorization\BrowserQueryPrincipalService;
use App\Services\Authorization\DatasetQueryAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrowserSessionAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_create_command_prints_the_id_needed_for_dataset_grants(): void
    {
        $this->artisan('user:create')
            ->expectsQuestion('Enter the username?', 'browser-command-user')
            ->expectsQuestion('Enter the email?', 'browser-command-user@example.test')
            ->expectsQuestion('Enter the server ip address?', '127.0.0.200')
            ->expectsOutput('User created successfully (ID: 1).')
            ->assertSuccessful();
    }

    public function test_sanctum_token_establishes_a_real_query_session(): void
    {
        config()->set('config.operator_auth.bypass', false);
        $user = $this->createUser('browser-session-login');
        $dataset = Dataset::query()->create([
            'dataset_id' => 'browser-session-login-dataset',
            'name' => 'Browser Session Login Dataset',
            'description' => null,
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_browser-session-login-dataset',
            'neo4j_namespace' => 'graph_browser-session-login-dataset',
            'created_at' => now(),
        ]);
        app(DatasetQueryAuthorizationService::class)->grantQueryAccess($user, $dataset);
        $token = $user->createToken('browser-session-test', ['query'])->plainTextToken;

        $this->withHeader('Origin', $this->applicationOrigin())
            ->withSession(['_token' => 'browser-session-csrf'])
            ->withToken($token)
            ->postJson('/api/auth/session', [], ['X-CSRF-TOKEN' => 'browser-session-csrf'])
            ->assertOk()
            ->assertExactJson(['authenticated' => true]);

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')
            ->withHeader('Origin', 'https://untrusted-ui.example.test')
            ->getJson('/api/query/datasets')
            ->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Origin', $this->applicationOrigin())
            ->getJson('/api/query/datasets')
            ->assertOk()
            ->assertExactJson([
                'datasets' => [[
                    'dataset_id' => 'browser-session-login-dataset',
                    'name' => 'Browser Session Login Dataset',
                ]],
            ]);

        $this->withoutVite();
        $this->get('/hawki-rag-playground')
            ->assertOk()
            ->assertSee('"operatorAuthorized":false', false)
            ->assertSee('"queryAuthenticated":true', false);

        $this->getJson('/api/settings/config')
            ->assertUnauthorized();
        $this->withHeader('X-CSRF-TOKEN', 'browser-session-csrf')
            ->postJson('/api/rag/neo4j/clear')
            ->assertUnauthorized();
    }

    public function test_invalid_token_cannot_establish_a_browser_session(): void
    {
        $this->withToken('invalid-token')
            ->postJson('/api/auth/session')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_valid_bearer_without_stateful_headers_cannot_establish_a_browser_session(): void
    {
        $user = $this->createUser('stateless-session-exchange');
        $token = $user->createToken('stateless-session-exchange-test', ['query'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/auth/session')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_same_origin_session_exchange_requires_csrf_outside_the_test_environment(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');
        $user = $this->createUser('csrf-session-exchange');
        $token = $user->createToken('csrf-session-exchange-test', ['query'])->plainTextToken;

        $this->withHeader('Origin', $this->applicationOrigin())
            ->withSession(['_token' => 'csrf-session-token'])
            ->withToken($token)
            ->postJson('/api/auth/session')
            ->assertStatus(419);
    }

    public function test_removed_user_token_cannot_establish_a_browser_session(): void
    {
        $user = $this->createUser('removed-browser-session', removed: true);
        $token = $user->createToken('removed-session-test')->plainTextToken;

        $this->withHeader('Origin', $this->applicationOrigin())
            ->withSession(['_token' => 'removed-session-csrf'])
            ->withToken($token)
            ->postJson('/api/auth/session', [], ['X-CSRF-TOKEN' => 'removed-session-csrf'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_token_without_query_ability_cannot_establish_a_browser_session(): void
    {
        $user = $this->createUser('limited-browser-session');
        $token = $user->createToken('limited-session-test', ['documents:read'])->plainTextToken;

        $this->withHeader('Origin', $this->applicationOrigin())
            ->withSession(['_token' => 'limited-session-csrf'])
            ->withToken($token)
            ->postJson('/api/auth/session', [], ['X-CSRF-TOKEN' => 'limited-session-csrf'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_token_without_query_ability_cannot_call_canonical_query_routes(): void
    {
        config()->set('config.operator_auth.bypass', false);
        $user = $this->createUser('limited-browser-query');
        $token = $user->createToken('limited-query-test', ['documents:read'])->plainTextToken;
        Http::fake();

        $this->withToken($token)
            ->getJson('/api/query/datasets')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->withToken($token)
            ->postJson('/api/query', [
                'dataset_id' => 'not-authorized',
                'query' => 'This request must never reach the RAG bridge.',
            ])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->withToken($token)
            ->postJson('/'.ltrim((string) config('mcp.server'), '/'), [])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_query_token_is_limited_to_canonical_query_operations(): void
    {
        config()->set('config.operator_auth.bypass', false);
        $user = $this->createUser('internal-query-only');
        $dataset = Dataset::query()->create([
            'dataset_id' => 'internal-query-only-dataset',
            'name' => 'Internal Query Only Dataset',
            'description' => null,
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_internal-query-only-dataset',
            'neo4j_namespace' => 'graph_internal-query-only-dataset',
            'created_at' => now(),
        ]);
        app(DatasetQueryAuthorizationService::class)->grantQueryAccess($user, $dataset);
        $token = $user->createToken('internal-query-only-test', ['query'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/query/datasets')
            ->assertOk()
            ->assertJsonPath('datasets.0.dataset_id', 'internal-query-only-dataset');

        $this->withToken($token)
            ->getJson('/api/pipeline/tasks')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Operator authentication required.');

        $this->withToken($token)
            ->getJson('/api/ping')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Operator authentication required.');
    }

    public function test_reauthentication_destroys_the_previous_query_session_id(): void
    {
        $secondUser = $this->createUser('second-query-session');
        $handler = new ArraySessionHandler(120);
        $session = new Store('query-session-test', $handler);
        $firstSessionId = str_repeat('a', 40);
        $session->setId($firstSessionId);
        $session->start();
        $session->put('hawki_rag.query_user_id', '1');
        $session->save();
        $session->setId($firstSessionId);
        $session->start();

        $request = Request::create('/api/auth/session', 'POST');
        $request->setLaravelSession($session);
        app(BrowserQueryPrincipalService::class)->establishSession($request, $secondUser);

        $this->assertNotSame($firstSessionId, $session->getId());
        $this->assertSame('', $handler->read($firstSessionId));
        $this->assertSame((string) $secondUser->id, $session->get('hawki_rag.query_user_id'));
    }

    private function createUser(string $username, bool $removed = false): User
    {
        return User::query()->create([
            'username' => $username,
            'email' => $username.'@example.test',
            'ip' => '127.0.0.'.random_int(1, 254),
            'isRemoved' => $removed,
        ]);
    }

    private function applicationOrigin(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
