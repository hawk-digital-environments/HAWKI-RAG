<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;

class HawkiRagExperienceRouteTest extends TestCase
{
    public function test_root_opens_the_admin_experience(): void
    {
        $this->get('/')
            ->assertRedirect('/admin');
    }

    public function test_swagger_redirect_uses_the_configured_deployment_path(): void
    {
        config(['app.asset_base_path' => '/']);
        $this->get('/swagger')
            ->assertRedirect('/swagger/index.html');

        config(['app.asset_base_path' => '/hawki-rag/']);
        $this->get('/swagger')
            ->assertRedirect('/hawki-rag/swagger/index.html');
    }

    public function test_admin_experience_page_mounts_svelte_shell(): void
    {
        $this->withoutVite();

        $this->get('/admin')
            ->assertOk()
            ->assertSee('HAWKI-RAG Admin')
            ->assertSee('data-hawki-rag-experience', false)
            ->assertSee('"adminRoutes"', false)
            ->assertSee('"key":"pipeline"', false)
            ->assertSee('"key":"datasets"', false)
            ->assertSee('"key":"graph"', false)
            ->assertSee('"key":"retrieve"', false)
            ->assertDontSee('"key":"admin"', false)
            ->assertDontSee('"key":"analytics"', false)
            ->assertDontSee('"key":"health"', false)
            ->assertDontSee('"key":"settings"', false);
    }

    public function test_admin_world_aliases_point_to_current_surfaces(): void
    {
        $this->withoutVite();

        $this->get('/admin')
            ->assertOk()
            ->assertSee('HAWKI-RAG Admin')
            ->assertSee('"activeSection":"admin"', false);

        $this->get('/admin/pipeline')
            ->assertRedirect('/pipeline-controller');

        $this->get('/admin/datasets')
            ->assertRedirect('/datasets');

        $this->get('/admin/graph')
            ->assertRedirect('/neo4j-graph-explorer');

        $this->get('/admin/retrieve')
            ->assertRedirect('/hawki-rag-playground');

        $this->get('/admin/health-repair')
            ->assertRedirect('/pipeline-health');

        $this->get('/admin/settings')
            ->assertRedirect('/settings');
    }
}
