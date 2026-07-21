<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use App\Http\Requests\Health\HealthCheckRequest;
use App\Models\User;
use App\Services\Health\HawkiRagSystemGateService;
use App\Services\Pipeline\Health\PipelineHealthService;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HealthRequestValidationTest extends TestCase
{
    public function test_health_endpoints_reject_invalid_timeouts(): void
    {
        $this->getJson('/pipeline/health?timeout=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timeout');

        $this->getJson('/health/system-gate?timeout=31')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timeout');
    }

    public function test_internal_health_endpoints_validate_after_operator_authentication(): void
    {
        Sanctum::actingAs(new User([
            'username' => 'health-operator',
            'email' => 'health-operator@example.test',
            'ip' => '127.0.0.81',
        ]), ['operator']);

        $this->getJson('/api/pipeline/health?timeout=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timeout');

        $this->getJson('/api/health/system-gate?timeout=31')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timeout');
    }

    public function test_health_request_preserves_nullable_and_explicit_timeouts(): void
    {
        $defaultRequest = HealthCheckRequest::create('/pipeline/health', 'GET');
        $defaultRequest->setContainer($this->app);
        $defaultRequest->validateResolved();

        $explicitRequest = HealthCheckRequest::create('/pipeline/health?timeout=3', 'GET');
        $explicitRequest->setContainer($this->app);
        $explicitRequest->validateResolved();

        $this->assertNull($defaultRequest->timeout());
        $this->assertSame(3, $explicitRequest->timeout());
    }

    public function test_health_controllers_preserve_their_endpoint_defaults(): void
    {
        $this->app->instance(PipelineHealthService::class, new FakePipelineHealthService);
        $this->app->instance(HawkiRagSystemGateService::class, new FakeHawkiRagSystemGateService);

        $this->getJson('/pipeline/health')
            ->assertOk()
            ->assertJsonPath('checks.0.detail', '5');
        $this->getJson('/pipeline/health?timeout=3')
            ->assertOk()
            ->assertJsonPath('checks.0.detail', '3');
        $this->getJson('/health/system-gate')
            ->assertOk()
            ->assertJsonPath('timeout', null);
        $this->getJson('/health/system-gate?timeout=3')
            ->assertOk()
            ->assertJsonPath('timeout', 3);
    }
}

readonly class FakePipelineHealthService extends PipelineHealthService
{
    public function __construct() {}

    public function check(int $timeout): array
    {
        return [[
            'name' => 'timeout',
            'status' => 'ok',
            'detail' => (string) $timeout,
            'fix' => '',
        ]];
    }
}

readonly class FakeHawkiRagSystemGateService extends HawkiRagSystemGateService
{
    public function __construct() {}

    public function report(?int $timeout = null): array
    {
        return [
            'success' => true,
            'timeout' => $timeout,
        ];
    }
}
