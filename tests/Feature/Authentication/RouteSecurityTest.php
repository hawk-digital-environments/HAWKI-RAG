<?php

declare(strict_types=1);

namespace Tests\Feature\Authentication;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RouteSecurityTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_operator_workspaces_render_without_an_admin_gate(): void
    {
        $this->withoutVite();

        foreach ([
            '/pipeline-controller' => 'data-pipeline-controller-dashboard',
            '/datasets' => 'data-datasets-dashboard',
            '/settings' => 'data-settings-dashboard',
            '/hawki-rag-playground' => 'data-hawki-rag-playground',
            '/neo4j-graph-explorer' => 'data-neo4j-graph-dashboard',
            '/pipeline-health' => 'data-pipeline-health-dashboard',
        ] as $path => $rootAttribute) {
            $this->get($path)
                ->assertOk()
                ->assertSee($rootAttribute, false);
        }
    }

    public function test_operator_api_is_available_without_authentication(): void
    {
        $this->getJson('/api/ping')
            ->assertOk()
            ->assertExactJson(['pong' => true])
            ->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->getJson('/api/settings/config')
            ->assertOk();
    }

    public function test_operator_api_responses_are_not_cacheable(): void
    {
        $response = $this->getJson('/api/settings/config')
            ->assertOk()
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Expires', '0');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_query_api_reports_when_there_is_no_sole_active_user(): void
    {
        $this->withSession(['_token' => 'test-token'])
            ->postJson('/api/query', ['query' => 'hello'], ['X-CSRF-TOKEN' => 'test-token'])
            ->assertStatus(503)
            ->assertExactJson([
                'message' => 'Query access requires exactly one active user.',
                'error' => 'single_user_query_principal_unavailable',
            ]);
    }

    public function test_canonical_api_cors_requires_an_explicit_allowed_origin(): void
    {
        config()->set('cors.allowed_origins', [
            'https://trusted-ui.example.test',
            'https://admin-ui.example.test',
        ]);

        $this->withHeaders(['Origin' => 'https://trusted-ui.example.test'])
            ->getJson('/api/ping')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://trusted-ui.example.test');

        $this->withHeaders(['Origin' => 'https://evil.example.test'])
            ->getJson('/api/ping')
            ->assertOk()
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
