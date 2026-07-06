<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class HawkiRagExperienceRouteTest extends TestCase
{
    public function test_root_opens_the_operator_experience(): void
    {
        $this->get('/')
            ->assertRedirect('/admin');
    }

    public function test_operator_experience_page_mounts_svelte_shell(): void
    {
        $this->withoutVite();

        $this->get('/admin')
            ->assertOk()
            ->assertSee('HAWKI-RAG Operator')
            ->assertSee('data-hawki-rag-experience', false)
            ->assertSee('"operatorRoutes"', false)
            ->assertSee('"key":"pipeline"', false)
            ->assertSee('"key":"heaps"', false)
            ->assertSee('"key":"graph"', false)
            ->assertSee('"key":"search"', false)
            ->assertDontSee('"key":"operator"', false)
            ->assertDontSee('"key":"analytics"', false)
            ->assertDontSee('"key":"health"', false)
            ->assertDontSee('"key":"settings"', false);
    }

    public function test_operator_world_aliases_point_to_current_surfaces(): void
    {
        $this->withoutVite();

        $this->get('/admin')
            ->assertOk()
            ->assertSee('HAWKI-RAG Operator')
            ->assertSee('"activeSection":"operator"', false);

        $this->get('/admin/pipeline')
            ->assertRedirect('/pipeline-controller');

        $this->get('/admin/heaps')
            ->assertRedirect('/heaps');

        $this->get('/admin/datasets')
            ->assertRedirect('/heaps');

        $this->get('/admin/graph')
            ->assertRedirect('/neo4j-graph-explorer');

        $this->get('/admin/search')
            ->assertRedirect('/hawki-rag-search');

        $this->get('/admin/retrieve')
            ->assertRedirect('/hawki-rag-search');

        $this->get('/admin/health-repair')
            ->assertRedirect('/pipeline-health');

        $this->get('/admin/settings')
            ->assertRedirect('/settings');
    }
}
