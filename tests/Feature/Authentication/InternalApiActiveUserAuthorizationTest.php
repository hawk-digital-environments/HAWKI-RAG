<?php

declare(strict_types=1);

namespace Tests\Feature\Authentication;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalApiActiveUserAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_removed_operator_token_is_forbidden_from_operator_and_health_routes(): void
    {
        $user = $this->createUser('removed-operator', '127.0.0.221', removed: true);
        $token = $user->createToken('removed-operator', ['operator'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/datasets')
            ->assertForbidden();

        $this->withToken($token)
            ->getJson('/api/ping')
            ->assertForbidden();
    }

    public function test_removed_query_token_is_forbidden_from_query_and_mcp_routes(): void
    {
        $user = $this->createUser('removed-query', '127.0.0.222', removed: true);
        $token = $user->createToken('removed-query', ['query'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/query/datasets')
            ->assertForbidden();

        $this->withToken($token)
            ->postJson('/'.ltrim((string) config('mcp.server'), '/'), [])
            ->assertForbidden();
    }

    public function test_active_operator_token_retains_access_to_internal_operator_and_health_routes(): void
    {
        $operator = $this->createUser('active-operator', '127.0.0.223');
        $operatorToken = $operator->createToken('active-operator', ['operator'])->plainTextToken;

        $this->withToken($operatorToken)
            ->getJson('/api/datasets')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'datasets' => [],
            ]);

        $this->withToken($operatorToken)
            ->getJson('/api/ping')
            ->assertOk()
            ->assertExactJson(['pong' => true]);
    }

    public function test_active_query_token_retains_access_to_internal_query_routes(): void
    {
        $queryUser = $this->createUser('active-query', '127.0.0.224');
        $queryToken = $queryUser->createToken('active-query', ['query'])->plainTextToken;

        $this->withToken($queryToken)
            ->getJson('/api/query/datasets')
            ->assertOk()
            ->assertExactJson(['datasets' => []]);
    }

    public function test_public_liveness_route_remains_available_without_authentication(): void
    {
        $this->get('/up')->assertNoContent();
    }

    private function createUser(string $username, string $ip, bool $removed = false): User
    {
        return User::query()->create([
            'username' => $username,
            'email' => $username.'@example.test',
            'ip' => $ip,
            'isRemoved' => $removed,
        ]);
    }
}
