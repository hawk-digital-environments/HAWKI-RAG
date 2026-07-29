<?php

declare(strict_types=1);

namespace Tests\Feature\Authentication;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class UserManagementCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_create_command_prints_the_persisted_user_id(): void
    {
        $this->artisan('user:create')
            ->expectsQuestion('Enter the username?', 'command-user')
            ->expectsQuestion('Enter the email?', 'command-user@example.test')
            ->expectsQuestion('Enter the server ip address?', '127.0.0.200')
            ->expectsOutput('User created successfully (ID: 1).')
            ->assertSuccessful();
    }

    public function test_user_role_command_promotes_and_demotes_a_persisted_user(): void
    {
        $user = User::query()->create([
            'username' => 'role-command',
            'email' => 'role-command@example.test',
            'ip' => '127.0.0.201',
        ]);

        $this->artisan('user:role', [
            'userId' => (string) $user->id,
            'role' => 'admin',
        ])->expectsOutput("User {$user->id} role updated to admin.")
            ->assertSuccessful();

        $this->assertSame('admin', $user->refresh()->role->value);

        $this->artisan('user:role', [
            'userId' => (string) $user->id,
            'role' => 'user',
        ])->expectsOutput("User {$user->id} role updated to user.")
            ->assertSuccessful();

        $this->assertSame('user', $user->refresh()->role->value);
    }
}
