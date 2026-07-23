<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListDatasetsRequestAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_tokenless_browser_session_passes_the_operator_gate(): void
    {
        config()->set('config.operator_auth.bypass', false);
        $user = $this->createUser('browser-session');

        $this->actingAs($user)
            ->withHeader('Origin', rtrim((string) config('app.url'), '/'))
            ->getJson('/api/datasets')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'datasets' => [],
            ]);
    }

    public function test_scoped_local_bypass_passes_the_operator_gate_without_a_user(): void
    {
        config()->set('config.operator_auth.bypass', true);
        config()->set('config.operator_auth.bypass_environments', [app()->environment()]);

        $this->getJson('/api/datasets')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'datasets' => [],
            ]);
    }

    public function test_operator_bearer_user_is_available_to_the_form_request_gate(): void
    {
        config()->set('config.operator_auth.bypass', false);
        $user = $this->createUser('bearer');
        $token = $user->createToken('list-datasets', ['operator'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/datasets')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'datasets' => [],
            ]);
    }

    public function test_removed_browser_session_is_rejected_before_dataset_listing(): void
    {
        config()->set('config.operator_auth.bypass', false);
        $user = $this->createUser('removed-browser-session', removed: true);

        $this->actingAs($user)
            ->withHeader('Origin', rtrim((string) config('app.url'), '/'))
            ->getJson('/api/datasets')
            ->assertUnauthorized();
    }

    private function createUser(string $suffix, bool $removed = false): User
    {
        return User::query()->create([
            'username' => 'list-datasets-'.$suffix,
            'email' => 'list-datasets-'.$suffix.'@example.test',
            'ip' => 'list-datasets-'.$suffix,
            'isRemoved' => $removed,
        ]);
    }
}
