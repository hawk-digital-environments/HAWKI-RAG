<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Services\Authorization\AuthorizationService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthorizationServiceTest extends TestCase
{
    public function test_document_access_is_allowed_without_permission_graph_when_api_enforcement_is_disabled(): void
    {
        config()->set('authz.document_api_enforced', false);
        Http::fake();

        $this->assertTrue(app(AuthorizationService::class)->canViewDocument(null, 'doc-1'));
        Http::assertNothingSent();
    }

    public function test_document_access_fails_closed_for_missing_user_when_api_enforcement_is_enabled(): void
    {
        config()->set('authz.document_api_enforced', true);
        Http::fake();

        $this->assertFalse(app(AuthorizationService::class)->canViewDocument(null, 'doc-1'));
        Http::assertNothingSent();
    }
}
