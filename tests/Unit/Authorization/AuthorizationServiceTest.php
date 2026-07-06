<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Models\UserIdentity;
use App\Services\Authorization\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthorizationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_access_is_allowed_without_permission_graph_when_api_enforcement_is_disabled(): void
    {
        config()->set('authz.document_api_enforced', false);
        Http::fake();

        $this->assertTrue(app(AuthorizationService::class)->canViewDocument(null, 'doc-1'));
        Http::assertNothingSent();
    }

    public function test_document_access_fails_closed_for_missing_user_when_api_enforcement_is_enabled(): void
    {
        config()->set('authz.enabled', true);
        config()->set('authz.document_api_enforced', true);
        Http::fake();

        $this->assertFalse(app(AuthorizationService::class)->canViewDocument(null, 'doc-1'));
        Http::assertNothingSent();
    }

    public function test_document_api_enforcement_is_ignored_when_authorization_is_disabled(): void
    {
        config()->set('authz.enabled', false);
        config()->set('authz.document_api_enforced', true);
        Http::fake();

        $service = app(AuthorizationService::class);

        $this->assertFalse($service->documentApiEnforced());
        $this->assertTrue($service->canViewDocument(null, 'doc-1'));
        Http::assertNothingSent();
    }

    public function test_retrieval_context_prefers_stored_authorization_identity(): void
    {
        $user = $this->actingAsApiUser();
        UserIdentity::query()->create([
            'user_id' => $user->id,
            'issuer' => 'https://issuer.test',
            'subject' => 'subject-1',
            'provider' => 'keycloak',
            'external_user_id' => 'student-42',
        ]);

        $context = app(AuthorizationService::class)->retrievalContextFor($user);

        $this->assertNotNull($context);
        $this->assertSame('keycloak', $context->provider);
        $this->assertSame('student-42', $context->userId);
        $this->assertSame([
            'provider' => 'keycloak',
            'user_id' => 'student-42',
        ], $context->toArray());
    }

    public function test_retrieval_context_falls_back_to_local_user_identity(): void
    {
        $user = $this->actingAsApiUser();

        $context = app(AuthorizationService::class)->retrievalContextFor($user);

        $this->assertNotNull($context);
        $this->assertSame('local', $context->provider);
        $this->assertSame((string) $user->getAuthIdentifier(), $context->userId);
    }
}
