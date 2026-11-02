<?php

declare(strict_types=1);

namespace Tests\Feature\Query;

use App\Models\User;
use App\Services\Authorization\QueryTokenSigner;
use App\Services\Rag\Clients\PythonQueryClient;
use App\Services\Rag\RagProxyService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\QueryBffFixture;
use Tests\TestCase;

class PythonQueryBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_legacy_path_executes_only_the_existing_proxy(): void
    {
        self::assertSame('laravel', config('config.query_backend'));
        $user = $this->actingAsApiUser();
        $legacy = $this->createMock(RagProxyService::class);
        $legacy->expects(self::once())->method('query')
            ->with(self::callback(fn (User $resolved) => $resolved->id === $user->id), ['dataset_id' => 'a', 'query' => 'Q'])
            ->willReturn(['status' => 200, 'payload' => ['answer' => 'Legacy']]);
        $this->app->instance(RagProxyService::class, $legacy);
        $this->prohibit(PythonQueryClient::class);
        $this->postJson('/api/query', ['dataset_id' => 'a', 'query' => 'Q'])->assertOk()->assertJsonPath('answer', 'Legacy');
    }

    public function test_absent_selector_in_an_older_config_cache_still_defaults_to_legacy(): void
    {
        $settings = config('config');
        unset($settings['query_backend']);
        config()->set('config', $settings);
        $this->actingAsApiUser();
        $legacy = $this->createMock(RagProxyService::class);
        $legacy->expects(self::once())->method('query')->willReturn(['status' => 200, 'payload' => ['answer' => 'Legacy']]);
        $this->app->instance(RagProxyService::class, $legacy);
        $this->prohibit(PythonQueryClient::class);
        $this->postJson('/api/query', ['dataset_id' => 'a', 'query' => 'Q'])->assertOk();
    }

    public function test_python_path_and_its_failure_never_execute_the_legacy_proxy(): void
    {
        $this->actingAsApiUser();
        config()->set('config.query_backend', 'python');
        $this->prohibit(RagProxyService::class);
        $python = $this->createMock(PythonQueryClient::class);
        $python->expects(self::once())->method('query')
            ->willReturn(['status' => 503, 'payload' => ['detail' => 'authentication_unavailable']]);
        $this->app->instance(PythonQueryClient::class, $python);
        $this->postJson('/api/query', ['dataset_id' => 'a', 'query' => 'Q'])
            ->assertStatus(503)->assertExactJson(['ok' => false, 'error' => 'authentication_unavailable', 'message' => 'Query execution failed.']);
    }

    #[DataProvider('invalidBackends')]
    public function test_invalid_backend_is_not_an_implicit_legacy_selection(mixed $backend): void
    {
        $this->actingAsApiUser();
        config()->set('config.query_backend', $backend);
        $this->prohibit(RagProxyService::class);
        $this->prohibit(PythonQueryClient::class);
        $this->postJson('/api/query', ['dataset_id' => 'a', 'query' => 'Q'])
            ->assertStatus(503)->assertJsonPath('error', 'query_backend_configuration_invalid');
    }

    public static function invalidBackends(): array
    {
        return [['Python'], [''], ['python '], ['invalid'], [null]];
    }

    public function test_browser_fields_and_headers_cannot_influence_the_internal_token(): void
    {
        $user = $this->actingAsApiUser();
        $fixture = new QueryBffFixture;
        JWT::$timestamp = QueryBffFixture::TIME;
        config()->set('config.query_backend', 'python');
        config()->set('config.python_query_url', 'http://python.test/v1/query');
        $this->app->instance(QueryTokenSigner::class, $fixture->signer());
        $this->prohibit(RagProxyService::class);
        $internalToken = null;
        Http::fake(function (Request $request) use ($user, $fixture, &$internalToken) {
            $internalToken = substr($request->header('Authorization')[0], 7);
            $headers = new \stdClass;
            $claims = JWT::decode($internalToken, new Key($fixture->publicPem, 'RS256'), $headers);
            self::assertSame((string) $user->id, $claims->sub);
            self::assertSame('default', $claims->tenant_id);
            self::assertSame('rag:query', $claims->scope);
            self::assertSame('hawki-bff', $claims->azp);
            self::assertSame('https://bff.example.test', $claims->iss);
            self::assertSame('hawki-backend', $claims->aud);
            self::assertFalse(isset($claims->client_id));
            self::assertSame('RS256', $headers->alg);
            self::assertSame('test-bff-key', $headers->kid);
            foreach (['Cookie', 'X-CSRF-TOKEN', 'X-User-Id', 'X-Tenant-Id', 'X-Forwarded-Host'] as $name) {
                self::assertFalse($request->hasHeader($name));
            }
            self::assertSame(['dataset_id' => 'a', 'query' => 'Q'], $request->data());

            return Http::response(['ok' => true, 'count' => 0, 'hits' => [], 'kg' => [], 'answer' => 'Safe answer', 'retrieval' => new \stdClass]);
        });
        try {
            $response = $this->withHeaders([
                'X-User-Id' => 'attacker', 'X-Tenant-Id' => 'attacker', 'X-CSRF-TOKEN' => 'browser-csrf',
                'X-Forwarded-Host' => 'attacker.test', 'Cookie' => 'browser-cookie',
            ])->postJson('/api/query', [
                'dataset_id' => 'a', 'query' => 'Q', 'sub' => 'attacker', 'tenant_id' => 'attacker',
                'scope' => '*', 'azp' => 'attacker', 'client_id' => 'attacker', 'iss' => 'attacker',
                'aud' => 'attacker', 'kid' => 'attacker', 'alg' => 'none',
            ])->assertOk();
            self::assertNotNull($internalToken);
            self::assertStringNotContainsString($internalToken, $response->getContent());
            self::assertStringNotContainsString('Authorization', $response->getContent());
            Http::assertSentCount(1);
        } finally {
            JWT::$timestamp = null;
            $fixture->close();
        }
    }

    public function test_python_branch_keeps_invalid_bearer_rejection_before_dispatch(): void
    {
        User::query()->create(['username' => 'sole', 'email' => 'sole@example.test', 'ip' => '127.9.0.1']);
        config()->set('config.query_backend', 'python');
        $this->prohibit(PythonQueryClient::class);
        $this->prohibit(RagProxyService::class);
        $this->withToken('invalid-token')->postJson('/api/query', ['dataset_id' => 'a', 'query' => 'Q'])->assertUnauthorized();
    }

    public function test_python_branch_preserves_the_existing_sole_user_resolution(): void
    {
        $user = User::query()->create(['username' => 'sole', 'email' => 'sole@example.test', 'ip' => '127.9.0.1']);
        config()->set('config.query_backend', 'python');
        $python = $this->createMock(PythonQueryClient::class);
        $python->expects(self::once())->method('query')
            ->with(self::callback(fn (User $resolved) => $resolved->id === $user->id), ['dataset_id' => 'a', 'query' => 'Q'])
            ->willReturn(['status' => 200, 'payload' => ['answer' => 'Python']]);
        $this->app->instance(PythonQueryClient::class, $python);
        $this->prohibit(RagProxyService::class);
        $this->postJson('/api/query', ['dataset_id' => 'a', 'query' => 'Q'])->assertOk();
    }

    public function test_dataset_listing_never_dispatches_to_either_query_backend(): void
    {
        $this->actingAsApiUser();
        config()->set('config.query_backend', 'python');
        $this->prohibit(PythonQueryClient::class);
        $this->prohibit(RagProxyService::class);
        $this->getJson('/api/query/datasets')->assertOk()->assertExactJson(['datasets' => []]);
    }

    /** @param class-string<PythonQueryClient|RagProxyService> $class */
    private function prohibit(string $class): void
    {
        $service = $this->createMock($class);
        $service->expects(self::never())->method('query');
        $this->app->instance($class, $service);
    }
}
