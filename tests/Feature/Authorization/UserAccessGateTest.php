<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\User;
use App\Services\User\Values\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class UserAccessGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_be_a_query_principal(): void
    {
        $user = $this->createUser('active');

        $this->assertNull($user->currentAccessToken());
        $this->assertTrue(Gate::forUser($user)->allows('access-active-user'));
        $this->assertTrue(Gate::forUser($user)->allows('access-query-principal'));
    }

    public function test_role_cannot_be_elevated_through_mass_assignment(): void
    {
        $user = new User(['role' => UserRole::Admin]);

        $this->assertSame(UserRole::User, $user->role);
    }

    public function test_removed_user_is_denied_query_access_without_relying_on_http_context(): void
    {
        $user = $this->createUser('removed', removed: true);

        $this->assertFalse(Gate::forUser($user)->allows('access-active-user'));
        $this->assertFalse(Gate::forUser($user)->allows('access-query-principal'));
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
