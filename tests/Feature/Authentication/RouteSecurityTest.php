<?php

declare(strict_types=1);

namespace Tests\Feature\Authentication;

use App\Models\User;
use App\Services\User\Values\UserRole;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RouteSecurityTest extends TestCase
{
    public function test_security_headers_are_added_to_web_responses(): void
    {
        $this->withoutVite();

        $response = $this->get('/hawki-rag-playground')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-DNS-Prefetch-Control', 'off')
            ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringContainsString("script-src-attr 'none'", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
        $response->assertDontSee('fonts.googleapis.com', false);
    }

    public function test_web_ui_does_not_require_an_admin_secret_to_render(): void
    {
        $this->withoutVite();

        $this->get('/pipeline-controller')
            ->assertOk()
            ->assertSee('Pipeline Controller');
    }

    public function test_pipeline_controller_page_stays_idle_without_admin_access(): void
    {
        $this->withoutVite();
        config()->set('config.admin_auth.bypass', false);

        $this->get('/pipeline-controller')
            ->assertOk()
            ->assertSee('"adminAuthorized":false', false)
            ->assertDontSee('pipeline-task-select', false);
    }

    public function test_other_admin_dashboards_stay_idle_without_admin_access(): void
    {
        $this->withoutVite();
        config()->set('config.admin_auth.bypass', false);

        $this->get('/datasets')
            ->assertOk()
            ->assertSee('datasets-dashboard-config', false)
            ->assertSee('"adminAuthorized":false', false)
            ->assertDontSee('datasets-document-search-form', false);

        $this->get('/settings')
            ->assertOk()
            ->assertSee('settings-dashboard-config', false)
            ->assertSee('"adminAuthorized":false', false)
            ->assertDontSee('settings-custom-converter-enabled', false);

        $this->get('/hawki-rag-playground')
            ->assertOk()
            ->assertSee('hawki-rag-playground-config', false)
            ->assertSee('"adminAuthorized":false', false)
            ->assertDontSee('query-form', false);

        $this->get('/neo4j-graph-explorer')
            ->assertOk()
            ->assertSee('neo4j-graph-dashboard-config', false)
            ->assertSee('"adminAuthorized":false', false)
            ->assertDontSee('graph-search-input', false);

        $this->get('/pipeline-health')
            ->assertOk()
            ->assertSee('pipeline-health-dashboard-config', false)
            ->assertSee('"adminAuthorized":false', false)
            ->assertDontSee('pipeline-health-metrics', false);
    }

    public function test_canonical_admin_api_requires_admin_authentication(): void
    {
        config()->set('config.admin_auth.bypass', false);

        $this->get('/api/pipeline/tasks')
            ->assertUnauthorized();

        $this->getJson('/api/pipeline/tasks')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Admin authentication required.')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_sensitive_routes_are_not_cacheable(): void
    {
        config()->set('config.admin_auth.bypass', false);

        $response = $this->getJson('/api/pipeline/tasks')
            ->assertUnauthorized()
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', '0');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->getJson('/api/settings/config')
            ->assertUnauthorized()
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', '0');
    }

    public function test_canonical_admin_endpoints_require_admin_authentication(): void
    {
        config()->set('config.admin_auth.bypass', false);

        $this->getJson('/api/settings/config')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Admin authentication required.');

        $this->getJson('/api/documents/uploads/download?source_url=upload%3A%2F%2Fsecret.pdf')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Admin authentication required.');

        $this->getJson('/api/health/system-gate')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Admin authentication required.');

        $this->getJson('/api/pipeline/health')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Admin authentication required.');

        $this->withSession(['_token' => 'test-token'])
            ->postJson('/api/query', ['query' => 'hello'], ['X-CSRF-TOKEN' => 'test-token'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_canonical_admin_endpoints_allow_tokens_with_explicit_admin_ability(): void
    {
        config()->set('config.admin_auth.bypass', false);
        $this->authenticateApiUser(new User([
            'username' => 'admin-test',
            'email' => 'admin-test@example.test',
            'ip' => '127.0.0.1',
        ]), ['admin']);

        $this->getJson('/api/settings/config')
            ->assertOk();
    }

    public function test_canonical_admin_endpoints_reject_regular_tokenless_browser_sessions(): void
    {
        config()->set('config.admin_auth.bypass', false);

        $this->actingAs(new User([
            'username' => 'browser-user',
            'email' => 'browser-user@example.test',
            'ip' => '127.0.0.2',
        ]))->withHeader('Origin', rtrim((string) config('app.url'), '/'))
            ->getJson('/api/settings/config')
            ->assertUnauthorized();
    }

    public function test_canonical_admin_endpoints_allow_admin_browser_sessions(): void
    {
        config()->set('config.admin_auth.bypass', false);
        $admin = new User([
            'username' => 'browser-admin',
            'email' => 'browser-admin@example.test',
            'ip' => '127.0.0.3',
        ]);
        $admin->role = UserRole::Admin;

        $this->actingAs($admin)
            ->withHeader('Origin', rtrim((string) config('app.url'), '/'))
            ->getJson('/api/settings/config')
            ->assertOk();
    }

    public function test_canonical_admin_endpoints_reject_removed_admin_browser_sessions(): void
    {
        config()->set('config.admin_auth.bypass', false);
        $admin = new User([
            'username' => 'removed-browser-admin',
            'email' => 'removed-browser-admin@example.test',
            'ip' => '127.0.0.4',
            'isRemoved' => true,
        ]);
        $admin->role = UserRole::Admin;

        $this->actingAs($admin)
            ->withHeader('Origin', rtrim((string) config('app.url'), '/'))
            ->getJson('/api/settings/config')
            ->assertUnauthorized();
    }

    public function test_canonical_admin_local_bypass_must_be_explicit_and_environment_scoped(): void
    {
        config()->set('config.admin_auth.bypass', true);
        config()->set('config.admin_auth.bypass_environments', ['production']);

        $this->getJson('/api/settings/config')
            ->assertUnauthorized();

        config()->set('config.admin_auth.bypass_environments', [app()->environment()]);

        $this->getJson('/api/settings/config')
            ->assertOk();
    }

    public function test_admin_bypass_is_hard_disabled_in_production(): void
    {
        config()->set('config.admin_auth.bypass', true);
        config()->set('config.admin_auth.bypass_environments', ['production']);
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->getJson('/api/settings/config')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Admin authentication required.');
    }

    public function test_canonical_api_cors_requires_an_explicit_allowed_origin(): void
    {
        config()->set('config.admin_auth.bypass', false);
        config()->set('cors.allowed_origins', [
            'https://trusted-ui.example.test',
            'https://admin-ui.example.test',
        ]);

        $this->withHeaders(['Origin' => 'https://trusted-ui.example.test'])
            ->getJson('/api/pipeline/tasks')
            ->assertUnauthorized()
            ->assertHeader('Access-Control-Allow-Origin', 'https://trusted-ui.example.test');

        $this->withHeaders(['Origin' => 'https://evil.example.test'])
            ->getJson('/api/pipeline/tasks')
            ->assertUnauthorized()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_bearer_query_requests_are_csrf_free_and_size_limited(): void
    {
        $this->authenticateApiUser(new User([
            'username' => 'api-test',
            'email' => 'api-test@example.test',
            'ip' => '127.0.0.1',
        ]), ['query']);

        $this->postJson('/api/query', ['query' => str_repeat('x', 4001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('query');
    }

    public function test_route_identifier_constraints_reject_suspicious_paths(): void
    {
        $this->getJson('/api/pipeline/tasks/..')
            ->assertNotFound();
    }

    /**
     * @param  non-empty-list<string>  $abilities
     */
    private function authenticateApiUser(User $user, array $abilities): void
    {
        Sanctum::actingAs($user);
        $user->withAccessToken(new PersonalAccessToken([
            'abilities' => $abilities,
        ]));
    }
}
