<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DatasetScopedGraphSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_semantic_graph_query_is_disabled_without_dataset_scoped_graph_data(): void
    {
        Http::fake();
        $user = User::query()->create([
            'username' => 'graph-reader',
            'email' => 'graph-reader@example.test',
            'ip' => '127.0.0.71',
        ]);
        $dataset = Dataset::query()->create([
            'dataset_id' => 'scoped-graph',
            'name' => 'Scoped graph',
            'description' => null,
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_scoped_graph',
            'neo4j_namespace' => 'hawki_scoped_graph',
        ]);
        DatasetGrant::query()->create([
            'dataset_id' => $dataset->dataset_id,
            'principal_type' => DatasetGrant::PRINCIPAL_USER,
            'principal_id' => (string) $user->getAuthIdentifier(),
            'permission' => DatasetGrant::PERMISSION_QUERY,
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/rag/neo4j/graph/semantic-search?dataset_id=scoped-graph&q=HAWKI')
            ->assertStatus(409)
            ->assertJsonPath('error', 'dataset_graph_not_ready')
            ->assertJsonPath('dataset_id', 'scoped-graph')
            ->assertJsonPath('warnings.0', 'No global graph fallback was executed.');

        Http::assertNothingSent();
    }

    public function test_semantic_graph_query_hides_an_ungranted_dataset(): void
    {
        $user = User::query()->create([
            'username' => 'ungranted-graph-reader',
            'email' => 'ungranted-graph-reader@example.test',
            'ip' => '127.0.0.72',
        ]);
        Dataset::query()->create([
            'dataset_id' => 'hidden-graph',
            'name' => 'Hidden graph',
            'description' => null,
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_hidden_graph',
            'neo4j_namespace' => 'hawki_hidden_graph',
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/rag/neo4j/graph/semantic-search?dataset_id=hidden-graph&q=HAWKI')
            ->assertNotFound()
            ->assertJsonPath('error', 'dataset_not_found');
    }
}
