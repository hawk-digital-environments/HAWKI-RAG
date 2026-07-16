<?php

declare(strict_types=1);

namespace Tests\Feature\Query;

use App\Models\User;
use Tests\TestCase;

class HawkiRagPlaygroundPageTest extends TestCase
{
    public function test_playground_mounts_the_svelte_retrieval_console(): void
    {
        $this->withoutVite();
        config()->set('config.operator_auth.bypass', false);

        $this->get('/hawki-rag-playground')
            ->assertOk()
            ->assertSee('HAWKI-RAG Console')
            ->assertSee('data-hawki-rag-playground', false)
            ->assertSee('hawki-rag-playground-config', false)
            ->assertSee('"operatorAuthorized":false', false)
            ->assertSee('"queryAuthenticated":false', false)
            ->assertDontSee('query-form', false)
            ->assertDontSee('Scraper Pipeline');
    }

    public function test_local_operator_bypass_does_not_claim_a_query_principal(): void
    {
        $this->withoutVite();
        config()->set('config.operator_auth.bypass', true);
        config()->set('config.operator_auth.bypass_environments', [app()->environment()]);

        $this->get('/hawki-rag-playground')
            ->assertOk()
            ->assertSee('"operatorAuthorized":true', false)
            ->assertSee('"queryAuthenticated":false', false);
    }

    public function test_authenticated_browser_session_is_exposed_as_query_capable(): void
    {
        $this->withoutVite();
        config()->set('config.operator_auth.bypass', false);
        $user = new User([
            'username' => 'browser-query-user',
            'email' => 'browser-query-user@example.test',
            'ip' => '127.0.0.1',
        ]);

        $this->actingAs($user)
            ->get('/hawki-rag-playground')
            ->assertOk()
            ->assertSee('"operatorAuthorized":true', false)
            ->assertSee('"queryAuthenticated":true', false);
    }
}
