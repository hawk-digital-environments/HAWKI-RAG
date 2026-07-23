<?php

namespace Tests\Feature\Graph;

use Tests\TestCase;

class Neo4jGraphExplorerPageTest extends TestCase
{
    public function test_graph_explorer_has_its_own_page_and_is_removed_from_playground(): void
    {
        $this->withoutVite();
        config()->set('config.admin_auth.bypass', false);

        $this->get('/neo4j-graph-explorer')
            ->assertOk()
            ->assertSee('Neo4j Graph Explorer')
            ->assertSee('data-neo4j-graph-dashboard', false)
            ->assertSee('neo4j-graph-dashboard-config', false)
            ->assertSee('"adminAuthorized":false', false)
            ->assertDontSee('graph-search-input', false);

        $this->get('/hawki-rag-playground')
            ->assertOk()
            ->assertDontSee('Graph Workspace')
            ->assertDontSee('graph-search-input', false);
    }
}
