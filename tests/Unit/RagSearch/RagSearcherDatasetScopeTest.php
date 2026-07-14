<?php

declare(strict_types=1);

namespace Tests\Unit\RagSearch;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Models\User;
use App\Services\RagSearch\Exceptions\RagSearcherFailedException;
use App\Services\RagSearch\RagSearcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RagSearcherDatasetScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_bridge_search_carries_only_the_authorized_scope(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'hits' => [], 'kg' => []]),
        ]);
        $user = User::query()->create([
            'username' => 'mcp-reader',
            'email' => 'mcp-reader@example.test',
            'ip' => '127.0.0.81',
        ]);
        $dataset = Dataset::query()->create([
            'dataset_id' => 'mcp-dataset',
            'name' => 'MCP dataset',
            'description' => null,
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => 'hawki_mcp_dataset',
            'neo4j_namespace' => 'hawki_mcp_dataset',
        ]);
        DatasetGrant::query()->create([
            'dataset_id' => $dataset->dataset_id,
            'principal_type' => DatasetGrant::PRINCIPAL_USER,
            'principal_id' => (string) $user->getAuthIdentifier(),
            'permission' => DatasetGrant::PERMISSION_QUERY,
        ]);

        $result = $this->app->make(RagSearcher::class)
            ->withQuery('scoped search')
            ->withTopK(4)
            ->forDataset($user, $dataset->dataset_id)
            ->execute();

        $this->assertSame([], $result['results']);
        Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'authorized_scope.dataset_id') === 'mcp-dataset'
            && data_get($request->data(), 'authorized_scope.qdrant_collection') === 'hawki_mcp_dataset'
            && data_get($request->data(), 'authorized_scope.graph_enabled') === false
        );
    }

    public function test_direct_bridge_search_rejects_an_unscoped_call(): void
    {
        Http::fake();

        $this->expectException(RagSearcherFailedException::class);
        $this->expectExceptionMessage('no authorized dataset scope');

        $this->app->make(RagSearcher::class)
            ->withQuery('unsafe global search')
            ->execute();
    }
}
