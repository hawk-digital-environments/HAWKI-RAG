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
            ->assertSee('"key":"operator"', false)
            ->assertSee('"key":"health"', false);
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

        $this->get('/admin/datasets')
            ->assertRedirect('/datasets');

        $this->get('/admin/graph')
            ->assertRedirect('/neo4j-graph-explorer');

        $this->get('/admin/health-repair')
            ->assertRedirect('/pipeline-health');
    }
}
