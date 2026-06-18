<?php

declare(strict_types=1);

namespace Tests\Feature;

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
            ->assertDontSee('query-form', false)
            ->assertDontSee('Scraper Pipeline');
    }
}
