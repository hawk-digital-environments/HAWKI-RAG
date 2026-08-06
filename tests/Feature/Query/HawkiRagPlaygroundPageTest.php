<?php

declare(strict_types=1);

namespace Tests\Feature\Query;

use Tests\TestCase;

class HawkiRagPlaygroundPageTest extends TestCase
{
    public function test_playground_mounts_the_svelte_retrieval_console(): void
    {
        $this->withoutVite();

        $this->get('/hawki-rag-playground')
            ->assertOk()
            ->assertSee('HAWKI-RAG Console')
            ->assertSee('data-hawki-rag-playground', false)
            ->assertDontSee('hawki-rag-playground-config', false)
            ->assertDontSee('queryAuthenticated', false)
            ->assertDontSee('query-form', false)
            ->assertDontSee('Scraper Pipeline');
    }
}
