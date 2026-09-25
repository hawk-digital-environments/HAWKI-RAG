<?php

declare(strict_types=1);

namespace Tests\Feature\Query;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Services\RagSearch\HawkiKnowledgeBaseSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QueryLengthLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = $this->actingAsApiUser();
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

        Http::fake(['*' => Http::response(['ok' => true, 'hits' => [], 'kg' => []])]);
    }

    #[DataProvider('querySurfaces')]
    public function test_six_thousand_unicode_characters_reach_the_bridge_unchanged(string $surface): void
    {
        $query = str_repeat('🦅', 6000);

        $response = $this->sendQuery($surface, $query)->assertOk();
        if ($surface !== 'rest') {
            $response->assertJsonPath('result.isError', false);
        }

        Http::assertSentCount($surface === 'hawki-knowldgeBase' ? 2 : 1);
        Http::assertSent(static fn (Request $request): bool => $request['query'] === $query);
    }

    #[DataProvider('querySurfaces')]
    public function test_six_thousand_and_one_characters_are_rejected_before_retrieval(string $surface): void
    {
        $response = $this->sendQuery($surface, str_repeat('🦅', 6001));
        if ($surface === 'rest') {
            $response->assertUnprocessable()->assertJsonValidationErrors('query');
        } else {
            $response->assertOk()->assertJsonPath('result.isError', true);
        }

        Http::assertNothingSent();
    }

    public function test_both_mcp_tools_advertise_the_same_query_length_limit(): void
    {
        foreach (['query-search', 'hawki-knowldgeBase'] as $toolName) {
            $response = $this->postJson($this->mcpEndpoint($toolName), [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
            ])->assertOk();

            $tool = collect($response->json('result.tools'))->firstWhere('name', $toolName);
            $this->assertNotNull($tool);
            $this->assertSame(6000, $tool['inputSchema']['properties']['query']['maxLength']);
        }
    }

    /** @return array<string, array{string}> */
    public static function querySurfaces(): array
    {
        return [
            'REST' => ['rest'],
            'dataset MCP' => ['query-search'],
            'archive MCP' => ['hawki-knowldgeBase'],
        ];
    }

    private function sendQuery(string $surface, string $query): TestResponse
    {
        $arguments = ['query' => $query];
        if ($surface !== 'hawki-knowldgeBase') {
            $arguments['dataset_id'] = 'FULL_EMB_HAWK';
        }
        if ($surface === 'rest') {
            return $this->postJson('/api/query', $arguments);
        }

        return $this->postJson($this->mcpEndpoint($surface), [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $surface, 'arguments' => $arguments],
        ]);
    }

    private function mcpEndpoint(string $toolName): string
    {
        return $toolName === 'hawki-knowldgeBase'
            ? '/mcp/hawki-knowldgeBase'
            : '/'.ltrim((string) config('mcp.server'), '/');
    }
}
