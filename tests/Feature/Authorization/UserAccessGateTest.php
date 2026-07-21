<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class UserAccessGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_gates_work_without_an_http_access_token(): void
    {
        config()->set('config.operator_auth.bypass', false);
        $user = $this->createUser('active');

        $this->assertNull($user->currentAccessToken());
        $this->assertTrue(Gate::forUser($user)->allows('access-active-user'));
        $this->assertTrue(Gate::forUser($user)->allows('access-operator'));
        $this->assertTrue(Gate::forUser($user)->allows('access-query-principal'));
    }

    public function test_removed_user_is_denied_without_relying_on_http_context(): void
    {
        config()->set('config.operator_auth.bypass', false);
        $user = $this->createUser('removed', removed: true);

        $this->assertFalse(Gate::forUser($user)->allows('access-active-user'));
        $this->assertFalse(Gate::forUser($user)->allows('access-operator'));
        $this->assertFalse(Gate::forUser($user)->allows('access-query-principal'));
    }

    public function test_operator_gate_preserves_the_scoped_local_bypass_for_guests(): void
    {
        config()->set('config.operator_auth.bypass', true);
        config()->set('config.operator_auth.bypass_environments', [app()->environment()]);

        $this->assertTrue(Gate::allows('access-operator'));
    }

    private function createUser(string $suffix, bool $removed = false): User
    {
        return User::query()->create([
            'username' => 'user-access-'.$suffix,
            'email' => 'user-access-'.$suffix.'@example.test',
            'ip' => 'user-access-'.$suffix,
            'isRemoved' => $removed,
        ]);
    }
}
