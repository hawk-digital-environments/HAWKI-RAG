<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\User;
use App\Services\User\Values\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\TransientToken;
use Tests\TestCase;

class UserAccessGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_regular_user_is_not_an_admin_without_an_http_access_token(): void
    {
        config()->set('config.admin_auth.bypass', false);
        $user = $this->createUser('active');

        $this->assertNull($user->currentAccessToken());
        $this->assertTrue(Gate::forUser($user)->allows('access-active-user'));
        $this->assertFalse(Gate::forUser($user)->allows('access-admin'));
        $this->assertTrue(Gate::forUser($user)->allows('access-query-principal'));
    }

    public function test_active_admin_is_allowed_without_an_http_access_token(): void
    {
        config()->set('config.admin_auth.bypass', false);
        $user = $this->createUser('admin', role: UserRole::Admin);

        $this->assertTrue(Gate::forUser($user)->allows('access-admin'));
    }

    public function test_stateful_sanctum_sessions_still_require_the_persisted_admin_role(): void
    {
        config()->set('config.admin_auth.bypass', false);
        $regularUser = $this->createUser('transient-regular')
            ->withAccessToken(new TransientToken);
        $admin = $this->createUser('transient-admin', role: UserRole::Admin)
            ->withAccessToken(new TransientToken);

        $this->assertFalse(Gate::forUser($regularUser)->allows('access-admin'));
        $this->assertTrue(Gate::forUser($admin)->allows('access-admin'));
    }

    public function test_role_cannot_be_elevated_through_mass_assignment(): void
    {
        $user = new User(['role' => UserRole::Admin]);

        $this->assertSame(UserRole::User, $user->role);
    }

    public function test_removed_admin_is_denied_without_relying_on_http_context(): void
    {
        config()->set('config.admin_auth.bypass', false);
        $user = $this->createUser('removed', removed: true, role: UserRole::Admin);

        $this->assertFalse(Gate::forUser($user)->allows('access-active-user'));
        $this->assertFalse(Gate::forUser($user)->allows('access-admin'));
        $this->assertFalse(Gate::forUser($user)->allows('access-query-principal'));
    }

    public function test_admin_gate_preserves_the_scoped_local_bypass_for_guests(): void
    {
        config()->set('config.admin_auth.bypass', true);
        config()->set('config.admin_auth.bypass_environments', [app()->environment()]);

        $this->assertTrue(Gate::allows('access-admin'));
    }

    private function createUser(
        string $suffix,
        bool $removed = false,
        UserRole $role = UserRole::User,
    ): User {
        $user = User::query()->create([
            'username' => 'user-access-'.$suffix,
            'email' => 'user-access-'.$suffix.'@example.test',
            'ip' => 'user-access-'.$suffix,
            'isRemoved' => $removed,
        ]);
        $user->role = $role;
        $user->save();

        return $user;
    }
}
