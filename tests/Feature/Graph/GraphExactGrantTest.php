<?php

declare(strict_types=1);

namespace Tests\Feature\Graph;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Models\User;
use App\Services\Authorization\DatasetQueryAuthorizationService;
use App\Services\Graph\Neo4jGraphExplorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GraphExactGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_graph_actions_require_exact_grants_with_shipped_query_all_default(): void
    {
        // PHPUnit globally disables query-all. Restore the actual shipped default.
        config()->set(['config.query_auth.all_datasets_by_default' => true,
            'config.graph_ops_backend' => 'laravel', 'config.dataset_backend' => 'laravel']);
        $this->app->forgetInstance(DatasetQueryAuthorizationService::class);
        $user = User::query()->create(['username' => 'graph-reader', 'email' => 'reader@example.test', 'ip' => '127.0.0.1']);
        foreach (['a', 'b'] as $id) {
            Dataset::query()->create(['dataset_id' => $id, 'name' => $id, 'status' => 'active',
                'qdrant_collection' => 'vector-'.$id, 'neo4j_namespace' => 'graph-'.$id]);
        }
        DatasetGrant::query()->create(['dataset_id' => 'a', 'principal_type' => 'user',
            'principal_id' => (string) $user->getAuthIdentifier(), 'permission' => 'query']);
        // Historical ordinary queries keep their existing query-all behavior.
        $this->assertSame('b', app(DatasetQueryAuthorizationService::class)->authorize($user, 'b')->datasetId);
        $graph = $this->mock(Neo4jGraphExplorer::class);
        foreach (['overview', 'searchEntities', 'graphForNode', 'expand', 'semanticSearch'] as $method) {
            $graph->shouldReceive($method)->once()->andReturn(['ok' => true]);
        }
        Http::fake();
        $this->actingAs($user);
        foreach (['b', 'missing', 'a'] as $dataset) {
            foreach (['overview', 'search?q=secret', 'node?node_id=node', 'semantic-search?q=secret'] as $action) {
                $separator = str_contains($action, '?') ? '&' : '?';
                $this->getJson('/api/rag/neo4j/graph/'.$action.$separator.'dataset_id='.$dataset)
                    ->assertStatus($dataset === 'a' ? 200 : 404);
            }
            $this->postJson('/api/rag/neo4j/graph/expand', ['dataset_id' => $dataset, 'node_id' => 'node'])
                ->assertStatus($dataset === 'a' ? 200 : 404);
        }
        Http::assertNothingSent();
    }
}
