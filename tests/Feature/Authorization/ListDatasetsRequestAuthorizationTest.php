<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\User;
use App\Services\User\Values\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListDatasetsRequestAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('config.admin_auth.bypass', false);
        config()->set('config.admin_auth.accept_legacy_operator_ability', false);
    }

    public function test_active_regular_browser_session_is_rejected_by_the_admin_gate(): void
    {
        $user = $this->createUser('browser-session');

        $this->actingAs($user)
            ->withHeader('Origin', rtrim((string) config('app.url'), '/'))
            ->getJson('/api/datasets')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Admin authentication required.');
    }

    public function test_active_admin_browser_session_passes_the_admin_gate(): void
    {
        $user = $this->createUser('admin-browser-session', role: UserRole::Admin);

        $this->actingAs($user)
            ->withHeader('Origin', rtrim((string) config('app.url'), '/'))
            ->getJson('/api/datasets')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'datasets' => [],
            ]);
    }

    public function test_scoped_local_bypass_passes_the_admin_gate_without_a_user(): void
    {
        config()->set('config.admin_auth.bypass', true);
        config()->set('config.admin_auth.bypass_environments', [app()->environment()]);

        $this->getJson('/api/datasets')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'datasets' => [],
            ]);
    }

    public function test_admin_bearer_user_is_available_to_the_form_request_gate(): void
    {
        $user = $this->createUser('bearer');
        $token = $user->createToken('list-datasets', ['admin'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/datasets')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'datasets' => [],
            ]);
    }

    public function test_admin_role_does_not_replace_the_admin_bearer_ability(): void
    {
        $user = $this->createUser('admin-with-query-token', role: UserRole::Admin);
        $token = $user->createToken('query-only-admin', ['query'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/datasets')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Admin authentication required.');
    }

    public function test_legacy_operator_bearer_ability_requires_the_compatibility_switch(): void
    {
        $user = $this->createUser('legacy-bearer');
        $token = $user->createToken('legacy-list-datasets', ['operator'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/datasets')
            ->assertUnauthorized();

        config()->set('config.admin_auth.accept_legacy_operator_ability', true);

        $this->withToken($token)
            ->getJson('/api/datasets')
            ->assertOk();
    }

    public function test_wildcard_bearer_token_is_not_an_explicit_admin_credential(): void
    {
        $user = $this->createUser('wildcard-bearer');
        $token = $user->createToken('wildcard-list-datasets', ['*'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/datasets')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Admin authentication required.');
    }

    public function test_removed_admin_browser_session_is_rejected_before_dataset_listing(): void
    {
        $user = $this->createUser(
            'removed-admin-browser-session',
            removed: true,
            role: UserRole::Admin,
        );

        $this->actingAs($user)
            ->withHeader('Origin', rtrim((string) config('app.url'), '/'))
            ->getJson('/api/datasets')
            ->assertUnauthorized();
    }

    private function createUser(
        string $suffix,
        bool $removed = false,
        UserRole $role = UserRole::User,
    ): User {
        $user = User::query()->create([
            'username' => 'list-datasets-'.$suffix,
            'email' => 'list-datasets-'.$suffix.'@example.test',
            'ip' => 'list-datasets-'.$suffix,
            'isRemoved' => $removed,
        ]);
        $user->role = $role;
        $user->save();

        return $user;
    }
}
