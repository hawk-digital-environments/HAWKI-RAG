<?php

namespace Tests\Feature;

use App\Models\AuthorizationIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HawkiRagProxyControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_forwards_typed_authorization_context_to_bridge(): void
    {
        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');
        Http::fake([
            'http://bridge.test/query' => Http::response([
                'ok' => true,
                'count' => 0,
                'hits' => [],
            ], 200),
        ]);

        $user = $this->actingAsApiUser();
        AuthorizationIdentity::query()->create([
            'user_id' => $user->id,
            'issuer' => 'https://issuer.test',
            'subject' => 'subject-777',
            'provider' => 'keycloak',
            'external_user_id' => 'student-777',
        ]);

        $this->postJson('/api/query', [
            'query' => 'campus policy',
            'top_k' => 3,
            'preferred_tags' => ['policy'],
        ])->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://bridge.test/query'
            && $request->data()['top_k'] === 3
            && $request->data()['preferred_tags'] === ['policy']
            && ($request->data()['auth_context'] ?? null) === [
                'provider' => 'keycloak',
                'user_id' => 'student-777',
            ]);
    }
}
