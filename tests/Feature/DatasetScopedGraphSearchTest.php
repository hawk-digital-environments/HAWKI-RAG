<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DatasetScopedGraphSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_semantic_graph_query_enforces_the_selected_dataset_on_entities_and_relationships(): void
    {
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

        $otherDataset = Dataset::query()->create([
            'dataset_id' => 'other-graph',
            'name' => 'Other graph',
            'description' => null,
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_other_graph',
            'neo4j_namespace' => 'hawki_other_graph',
        ]);
        DatasetGrant::query()->create([
            'dataset_id' => $otherDataset->dataset_id,
            'principal_type' => DatasetGrant::PRINCIPAL_USER,
            'principal_id' => (string) $user->getAuthIdentifier(),
            'permission' => DatasetGrant::PERMISSION_QUERY,
        ]);

        Http::fake([
            rtrim((string) config('config.hawki_rag_bridge_url'), '/').'/query' => Http::response([
                'hits' => [],
                'kg' => [[
                    'subject' => 'HAWKI',
                    'relation' => 'USES',
                    'object' => 'Scoped Entity',
                ]],
                'retrieval' => ['rewrite' => ['entity_terms' => ['HAWKI']]],
            ]),
            $this->neo4jEndpoint() => Http::response([
                'results' => [[
                    'columns' => ['node', 'score'],
                    'data' => [[
                        'row' => [['name' => 'HAWKI'], 1.0],
                        'graph' => [
                            'nodes' => [[
                                'id' => 11,
                                'elementId' => 'scoped-node-11',
                                'labels' => ['Entity'],
                                'properties' => [
                                    'name' => 'HAWKI',
                                    'dataset_id' => 'scoped-graph',
                                    'neo4j_namespace' => 'hawki_scoped_graph',
                                ],
                            ]],
                            'relationships' => [],
                        ],
                    ]],
                ]],
                'errors' => [],
            ]),
        ]);
        Sanctum::actingAs($user, ['operator']);

        $this->getJson('/api/rag/neo4j/graph/semantic-search?dataset_id=scoped-graph&q=HAWKI')
            ->assertOk()
            ->assertJsonPath('dataset_id', 'scoped-graph')
            ->assertJsonPath('search_mode', 'dataset-scoped-semantic-rag')
            ->assertJsonPath('results.0.label', 'HAWKI')
            ->assertJsonMissing(['dataset_id' => 'other-graph']);

        Http::assertSent(function (Request $request): bool {
            $scope = $request->data()['authorized_scope'] ?? null;

            return $request->url() === rtrim((string) config('config.hawki_rag_bridge_url'), '/').'/query'
                && $scope === [
                    'dataset_id' => 'scoped-graph',
                    'qdrant_collection' => 'hawki_scoped_graph',
                    'neo4j_namespace' => 'hawki_scoped_graph',
                    'embedding_provider' => 'ollama',
                    'embedding_model' => 'bge-m3',
                    'graph_enabled' => true,
                ];
        });

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== $this->neo4jEndpoint()) {
                return false;
            }

            $statement = (string) data_get($request->data(), 'statements.0.statement');
            $parameters = data_get($request->data(), 'statements.0.parameters');

            return str_contains($statement, 'MATCH (subject:Entity)-[relationship:REL]->(object:Entity)')
                && str_contains($statement, 'subject.dataset_id = $dataset_id')
                && str_contains($statement, 'subject.neo4j_namespace = $neo4j_namespace')
                && str_contains($statement, 'relationship.dataset_id = $dataset_id')
                && str_contains($statement, 'relationship.neo4j_namespace = $neo4j_namespace')
                && str_contains($statement, 'object.dataset_id = $dataset_id')
                && str_contains($statement, 'object.neo4j_namespace = $neo4j_namespace')
                && ($parameters['dataset_id'] ?? null) === 'scoped-graph'
                && ($parameters['neo4j_namespace'] ?? null) === 'hawki_scoped_graph'
                && ! in_array('other-graph', $parameters, true)
                && ! in_array('hawki_other_graph', $parameters, true);
        });

        Http::assertSentCount(2);
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
        Sanctum::actingAs($user, ['operator']);

        $this->getJson('/api/rag/neo4j/graph/semantic-search?dataset_id=hidden-graph&q=HAWKI')
            ->assertNotFound()
            ->assertJsonPath('error', 'dataset_not_found');
    }

    private function neo4jEndpoint(): string
    {
        $baseUrl = rtrim((string) config('config.neo4j_http_url'), '/');
        $database = trim((string) config('config.neo4j_database')) ?: 'neo4j';

        return $baseUrl.'/db/'.rawurlencode($database).'/tx/commit';
    }
}
