<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class HawkiRagPlaygroundPageTest extends TestCase
{
    public function test_search_console_mounts_the_svelte_shell(): void
    {
        $this->withoutVite();

        $this->get('/hawki-rag-search')
            ->assertOk()
            ->assertSee('HAWKI RAG Search Console')
            ->assertSee('data-hawki-rag-playground', false)
            ->assertSee('hawki-rag-playground-config', false)
            ->assertDontSee('query-form', false)
            ->assertDontSee('Scraper Pipeline');
    }
}
