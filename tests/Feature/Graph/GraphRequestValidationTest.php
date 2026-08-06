<?php

declare(strict_types=1);

namespace Tests\Feature\Graph;

use App\Services\Graph\Neo4jGraphExplorer;
use Mockery\MockInterface;
use Tests\TestCase;

class GraphRequestValidationTest extends TestCase
{
    public function test_graph_requests_forward_validated_values_and_defaults(): void
    {
        $this->mock(Neo4jGraphExplorer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')
                ->once()
                ->with(80)
                ->andReturn(['ok' => true]);
            $mock->shouldReceive('searchEntities')
                ->once()
                ->with('alpha', 12)
                ->andReturn(['ok' => true]);
            $mock->shouldReceive('expand')
                ->once()
                ->with('node-1', 1, 80)
                ->andReturn(['ok' => true]);
            $mock->shouldReceive('graphForNode')
                ->once()
                ->with('node-2', 80)
                ->andReturn(['ok' => true]);
            $mock->shouldReceive('saveSnapshot')
                ->once()
                ->with(['scene' => ['nodes' => [], 'edges' => []]])
                ->andReturn(['ok' => true]);
        });

        $this->getJson('/api/rag/neo4j/graph/overview')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->getJson('/api/rag/neo4j/graph/search?q=alpha')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->postJson('/api/rag/neo4j/graph/expand', ['node_id' => 'node-1'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->getJson('/api/rag/neo4j/graph/node?node_id=node-2')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->postJson('/api/rag/neo4j/graph/snapshots', [
            'scene' => ['nodes' => [], 'edges' => []],
        ])->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_graph_requests_reject_invalid_input(): void
    {
        $this->getJson('/api/rag/neo4j/graph/overview?limit=4')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');

        $this->getJson('/api/rag/neo4j/graph/search?limit=12')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');

        $this->postJson('/api/rag/neo4j/graph/expand', [
            'node_id' => 'node-1',
            'depth' => 4,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('depth');

        $this->getJson('/api/rag/neo4j/graph/node?node_id=node-2&limit=251')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');

        $this->postJson('/api/rag/neo4j/graph/snapshots', ['scene' => 'invalid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scene');
    }
}
