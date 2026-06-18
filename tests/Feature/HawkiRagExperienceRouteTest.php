<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class HawkiRagExperienceRouteTest extends TestCase
{
    public function test_root_opens_the_hawki_rag_experience(): void
    {
        $this->get('/')
            ->assertRedirect('/hawki-rag');
    }

    public function test_hawki_rag_experience_page_mounts_svelte_shell(): void
    {
        $this->withoutVite();

        $this->get('/hawki-rag')
            ->assertOk()
            ->assertSee('HAWKI-RAG Experience')
            ->assertSee('data-hawki-rag-experience', false)
            ->assertSee('"key":"chats"', false)
            ->assertSee('"key":"health"', false);
    }

    public function test_user_world_aliases_point_to_current_surfaces(): void
    {
        $this->get('/hawki-rag/chats')
            ->assertRedirect('/hawki-rag-playground');

        $this->get('/hawki-rag/chats/chat-001')
            ->assertRedirect('/hawki-rag-playground?chat=chat-001');

        $this->get('/hawki-rag/spaces/default/sources')
            ->assertRedirect('/datasets?space=default');

        $this->get('/hawki-rag/spaces/default/topics')
            ->assertNotFound();
    }

    public function test_operator_world_aliases_point_to_current_surfaces(): void
    {
        $this->withoutVite();

        $this->get('/admin')
            ->assertOk()
            ->assertSee('HAWKI-RAG Experience')
            ->assertSee('"initialWorld":"admin"', false);

        $this->get('/admin/pipeline')
            ->assertRedirect('/pipeline-controller');

        $this->get('/admin/datasets')
            ->assertRedirect('/datasets');

        $this->get('/admin/graph')
            ->assertRedirect('/neo4j-graph-explorer');

        $this->get('/admin/health-repair')
            ->assertRedirect('/pipeline-health');
    }
}
