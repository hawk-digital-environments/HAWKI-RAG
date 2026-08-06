<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListDatasetsRequestAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_dataset_listing_is_available_without_authentication(): void
    {
        $this->getJson('/api/datasets')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'datasets' => [],
            ]);
    }

    public function test_user_state_does_not_reintroduce_an_operator_gate(): void
    {
        $removedUser = $this->createUser('removed-browser-session', removed: true);

        $this->actingAs($removedUser)
            ->withHeader('Origin', rtrim((string) config('app.url'), '/'))
            ->getJson('/api/datasets')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'datasets' => [],
            ]);
    }

    public function test_token_ability_does_not_control_the_unlocked_operator_api(): void
    {
        $user = $this->createUser('query-bearer');
        $token = $user->createToken('list-datasets', ['query'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/datasets')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'datasets' => [],
            ]);
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
