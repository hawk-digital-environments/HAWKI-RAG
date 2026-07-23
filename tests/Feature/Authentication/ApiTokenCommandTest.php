<?php

declare(strict_types=1);

namespace Tests\Feature\Authentication;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTokenCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_command_defaults_to_the_explicit_query_ability(): void
    {
        $user = $this->createUser('query-token');

        $this->artisan('user:token')
            ->expectsChoice(
                'How would you like to identify the user?',
                'Username',
                ['Username', 'Email Address', 'UserID'],
            )
            ->expectsQuestion('Please enter the Username', $user->username)
            ->expectsQuestion('Enter a name for the token (max 16 characters)', 'query-access')
            ->assertSuccessful();

        $this->assertSame(['query'], $user->tokens()->sole()->abilities);
    }

    public function test_token_command_issues_admin_only_when_explicitly_requested(): void
    {
        $user = $this->createUser('admin-token');

        $this->artisan('user:token', ['--abilities' => 'admin'])
            ->expectsChoice(
                'How would you like to identify the user?',
                'Username',
                ['Username', 'Email Address', 'UserID'],
            )
            ->expectsQuestion('Please enter the Username', $user->username)
            ->expectsQuestion('Enter a name for the token (max 16 characters)', 'admin-access')
            ->assertSuccessful();

        $this->assertSame(['admin'], $user->tokens()->sole()->abilities);
    }

    public function test_token_command_rejects_wildcard_abilities(): void
    {
        $user = $this->createUser('wildcard-token');

        $this->artisan('user:token', ['--abilities' => '*'])
            ->expectsChoice(
                'How would you like to identify the user?',
                'Username',
                ['Username', 'Email Address', 'UserID'],
            )
            ->expectsQuestion('Please enter the Username', $user->username)
            ->expectsQuestion('Enter a name for the token (max 16 characters)', 'wildcard')
            ->expectsOutput('Token ability * is invalid. Expected query or admin.')
            ->assertFailed();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    private function createUser(string $username): User
    {
        return User::query()->create([
            'username' => $username,
            'email' => $username.'@example.test',
            'ip' => '127.20.0.'.(User::query()->count() + 1),
        ]);
    }
}
