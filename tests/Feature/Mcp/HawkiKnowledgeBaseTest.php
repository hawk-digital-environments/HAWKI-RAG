<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Models\User;
use App\Services\RagSearch\HawkiKnowledgeBaseSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class HawkiKnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/mcp/hawki-knowldgeBase';

    public function test_route_requires_authentication(): void
    {
        $this->postJson(self::ENDPOINT, $this->rpc('tools/list'))->assertUnauthorized();
    }

    public function test_route_requires_query_ability(): void
    {
        $this->actingAsApiUser(['rag:text-ingest']);

        $this->postJson(self::ENDPOINT, $this->rpc('tools/list'))->assertForbidden();
    }

    public function test_endpoint_exposes_only_the_fixed_collection_tool_with_matching_output_schema(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson(self::ENDPOINT, $this->rpc('tools/list'));

        $response->assertOk()->assertJsonCount(1, 'result.tools')
            ->assertJsonPath('result.tools.0.name', 'hawki-knowldgeBase')
            ->assertJsonPath('result.tools.0.inputSchema.required', ['query']);
        $properties = $response->json('result.tools.0.inputSchema.properties');
        $this->assertSame(['query', 'top_k'], array_keys($properties));
        $this->assertSame(50, $properties['top_k']['maximum']);
        $this->assertSame(
            ['results', 'kg', 'rewrite_terms', 'collections'],
            array_keys($response->json('result.tools.0.outputSchema.properties')),
        );

        $this->postJson(self::ENDPOINT, $this->rpc('tools/call', [
            'name' => 'query-search',
            'arguments' => ['query' => 'another dataset', 'dataset_id' => 'assistant_11'],
        ]))->assertJsonPath('error.code', -32602);
    }

    public function test_search_queries_both_allowed_collections_and_caps_each_result_group(): void
    {
        $this->registerDatasets($this->actingAsApiUser());
        Http::fake(function (Request $request) {
            $collection = $request['authorized_scope']['qdrant_collection'];

            return Http::response([
                'hits' => array_map(static fn (int $index): array => [
                    'collection' => $collection,
                    'payload' => [
                        'content' => "{$collection} chunk {$index}",
                        'title' => "{$collection} source",
                        'page_url' => "https://example.test/{$collection}",
                    ],
                ], [1, 2, 3]),
                'kg' => [],
                'retrieval' => ['rewrite' => ['entity_terms' => ['HAWK']]],
            ]);
        });

        $response = $this->search(['query' => 'HAWK research projects', 'top_k' => 2]);

        $response->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonCount(4, 'result.structuredContent.results')
            ->assertJsonPath('result.structuredContent.collections', array_values(HawkiKnowledgeBaseSearch::DATASETS))
            ->assertJsonPath('result.structuredContent.results.0.metadata.collection', 'FULL_EMB_HAWK')
            ->assertJsonPath('result.structuredContent.results.2.metadata.collection', 'FULL_PROJ_HAWK')
            ->assertJsonPath('result.structuredContent.rewrite_terms', ['HAWK']);
        Http::assertSentCount(2);
        foreach (HawkiKnowledgeBaseSearch::DATASETS as $datasetId => $collection) {
            Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
                && str_ends_with($request->url(), '/query')
                && $request['authorized_scope']['dataset_id'] === $datasetId
                && $request['authorized_scope']['qdrant_collection'] === $collection
                && $request['authorized_scope']['embedding_model'] === 'bge-m3'
                && $request['authorized_scope']['graph_enabled'] === false
                && $request['top_k'] === 2
                && $request['generate'] === false
            );
        }
    }

    public function test_missing_access_to_either_dataset_stops_all_backend_queries(): void
    {
        $user = $this->actingAsApiUser();
        $this->registerDatasets($user);
        DatasetGrant::query()->where('dataset_id', 'FULL_PROJ_HAWK')->delete();
        Http::fake();

        $this->search(['query' => 'HAWK'])->assertJsonPath('result.isError', true);

        Http::assertNothingSent();
    }

    public function test_reassigned_collection_mapping_is_rejected_even_with_dataset_permission(): void
    {
        $this->registerDatasets($this->actingAsApiUser());
        Dataset::query()->where('dataset_id', 'FULL_PROJ_HAWK')->update([
            'qdrant_collection' => 'hawki_assistant_11',
        ]);
        Http::fake();

        $this->search(['query' => 'HAWK'])->assertJsonPath('result.isError', true);

        Http::assertNothingSent();
    }

    public function test_callers_cannot_override_collection_scope_or_request_invalid_limits(): void
    {
        $this->registerDatasets($this->actingAsApiUser());
        Http::fake();

        foreach ([
            ['dataset_id' => 'assistant_11'],
            ['collection' => 'hawki_assistant_11'],
            ['authorized_scope' => ['qdrant_collection' => 'hawki_assistant_11']],
            ['filters' => ['dataset_id' => 'assistant_11']],
            ['top_k' => 0],
            ['top_k' => 51],
            ['query' => str_repeat('x', 6001)],
        ] as $arguments) {
            $this->search(array_merge(['query' => 'HAWK'], $arguments))
                ->assertJsonPath('result.isError', true);
        }

        Http::assertNothingSent();
    }

    public function test_backend_failure_returns_an_error_instead_of_a_partial_success(): void
    {
        $this->registerDatasets($this->actingAsApiUser());
        Http::fakeSequence()
            ->push(['hits' => [['payload' => ['content' => 'first collection evidence']]]])
            ->push(['error' => 'unavailable'], 503);

        $response = $this->search(['query' => 'HAWK']);

        $response->assertJsonPath('result.isError', true);
        $this->assertNull($response->json('result.structuredContent'));
        Http::assertSentCount(2);
    }

    private function registerDatasets(User $user): void
    {
        foreach (HawkiKnowledgeBaseSearch::DATASETS as $datasetId => $collection) {
            Dataset::query()->create([
                'dataset_id' => $datasetId,
                'name' => $datasetId,
                'status' => Dataset::STATUS_ACTIVE,
                'qdrant_collection' => $collection,
                'neo4j_namespace' => $collection,
                'embedding_provider' => 'ollama',
                'embedding_model' => 'bge-m3',
            ]);
            DatasetGrant::query()->create([
                'dataset_id' => $datasetId,
                'principal_type' => DatasetGrant::PRINCIPAL_USER,
                'principal_id' => (string) $user->getAuthIdentifier(),
                'permission' => DatasetGrant::PERMISSION_QUERY,
            ]);
        }
    }

    private function search(array $arguments): TestResponse
    {
        return $this->postJson(self::ENDPOINT, $this->rpc('tools/call', [
            'name' => 'hawki-knowldgeBase',
            'arguments' => $arguments,
        ]));
    }

    private function rpc(string $method, array $params = []): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params];
    }
}
