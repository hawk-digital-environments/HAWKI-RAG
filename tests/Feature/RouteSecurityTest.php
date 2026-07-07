<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RouteSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_added_to_swagger_responses(): void
    {
        $response = $this->get('/swagger')
            ->assertRedirect('/swagger/index.html')
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

    public function test_removed_legacy_ui_surfaces_are_not_registered(): void
    {
        foreach (['/admin', '/hawki-rag-playground', '/hawki-rag-search', '/pipeline-controller', '/neo4j-graph-explorer', '/heaps', '/datasets', '/settings'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_removed_legacy_api_aliases_are_not_registered(): void
    {
        $this->postJson('/api/query', ['query' => 'hello'])->assertNotFound();
        $this->postJson('/api/retrieve/chunks', ['query' => 'hello'])->assertNotFound();
        $this->postJson('/api/search/documents', ['query' => 'hello'])->assertNotFound();
        $this->getJson('/api/datasets')->assertNotFound();
        $this->postJson('/api/ingest/text', ['text' => 'hello'])->assertNotFound();
        $this->getJson('/api/auth/heaps/heap-1/grants')->assertNotFound();
        $this->getJson('/api/auth/documents/doc-1/grants')->assertNotFound();
    }

    public function test_internal_api_requires_sanctum_authentication(): void
    {
        $this->get('/api/pipeline/tasks')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->getJson('/api/pipeline/tasks')
            ->assertUnauthorized()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_sensitive_routes_are_not_cacheable(): void
    {
        config()->set('config.operator_auth.bypass', false);

        $response = $this->getJson('/api/pipeline/tasks')
            ->assertUnauthorized()
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', '0');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->getJson('/settings/config')
            ->assertUnauthorized()
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', '0');
    }

    public function test_web_ui_operator_endpoints_require_operator_authentication(): void
    {
        config()->set('config.operator_auth.bypass', false);

        $this->getJson('/settings/config')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Operator authentication required.');
    }

    public function test_web_ui_operator_endpoints_allow_sanctum_users(): void
    {
        config()->set('config.operator_auth.bypass', false);
        Sanctum::actingAs(new User([
            'username' => 'operator-test',
            'email' => 'operator-test@example.test',
            'ip' => '127.0.0.1',
        ]));

        $this->getJson('/settings/config')
            ->assertOk();
    }

    public function test_web_ui_operator_local_bypass_must_be_explicit_and_environment_scoped(): void
    {
        config()->set('config.operator_auth.bypass', true);
        config()->set('config.operator_auth.bypass_environments', ['production']);

        $this->getJson('/settings/config')
            ->assertUnauthorized();

        config()->set('config.operator_auth.bypass_environments', [app()->environment()]);

        $this->getJson('/settings/config')
            ->assertOk();
    }

    public function test_internal_api_cors_requires_an_explicit_allowed_origin(): void
    {
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

    public function test_query_payloads_are_size_limited(): void
    {
        $this->actingAsApplication();

        $this->postJson('/api/search', ['query' => str_repeat('x', 4001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('query');
    }

    public function test_route_identifier_constraints_reject_suspicious_paths(): void
    {
        $this->getJson('/pipeline/tasks/..')
            ->assertNotFound();
    }
}
