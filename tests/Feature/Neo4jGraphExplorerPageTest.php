<?php

namespace Tests\Feature;

use Tests\TestCase;

class Neo4jGraphExplorerPageTest extends TestCase
{
    public function test_graph_explorer_has_its_own_page_and_is_removed_from_playground(): void
    {
        $this->withoutVite();

        $this->get('/neo4j-graph-explorer')
            ->assertOk()
            ->assertSee('Neo4j Graph Explorer')
            ->assertSee('Graph Workspace')
            ->assertSee('graph-search-input', false);

        $this->get('/hawki-rag-playground')
            ->assertOk()
            ->assertDontSee('Graph Workspace')
            ->assertDontSee('graph-search-input', false);
    }
}
