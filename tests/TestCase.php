<?php

namespace Tests;

use App\Models\SpecV2\Application;
use App\Models\SpecV2\Tenant;
use App\Models\User;
use App\Services\Authorization\ApplicationTokenService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function actingAsApiUser(): User
    {
        $user = User::query()->create([
            'username' => 'api-test-'.uniqid(),
            'email' => 'api-test-'.uniqid().'@example.test',
            'ip' => '127.0.0.'.random_int(1, 254),
        ]);

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array{application: Application, token: string}
     */
    protected function issueApplicationToken(array $attributes = []): array
    {
        $tenantId = (string) ($attributes['tenant_id'] ?? 'default');

        Tenant::query()->firstOrCreate(
            ['id' => $tenantId],
            [
                'name' => $attributes['tenant_name'] ?? ucfirst($tenantId).' Tenant',
                'metadata_json' => [],
            ],
        );

        $application = Application::query()->firstOrNew([
            'id' => $attributes['id'] ?? 'app-'.uniqid(),
        ]);
        $application->fill([
            'tenant_id' => $tenantId,
            'name' => $attributes['name'] ?? 'Test Application',
            'description' => $attributes['description'] ?? null,
            'permissions' => $attributes['permissions'] ?? [Application::PERMISSION_READS],
            'metadata_json' => $attributes['metadata_json'] ?? [],
        ]);
        if (! $application->exists) {
            $application->token_hash = null;
        }
        $application->save();

        $token = app(ApplicationTokenService::class)->issue($application);

        return [
            'application' => $application->fresh(),
            'token' => $token,
        ];
    }
}
