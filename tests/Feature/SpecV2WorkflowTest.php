<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpecV2WorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_heap_add_documents_and_search_uses_canonical_v2_routes(): void
    {
        config()->set('authz.enabled', true);
        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');
        config()->set('config.qdrant_http_url', 'http://qdrant.test');
        Http::fake([
            'http://bridge.test/ingest' => Http::response(['ok' => true], 200),
            'http://bridge.test/query' => Http::response([
                'ok' => true,
                'count' => 1,
                'hits' => [[
                    'id' => 'chunk-workflow-1',
                    'score' => 0.94,
                    'payload' => [
                        'document_id' => 'doc-workflow-1',
                        'content' => 'Workflow design guidance',
                        'heap' => 'workflow-heap',
                        'course' => 'design',
                    ],
                ]],
            ], 200),
            'http://qdrant.test/*' => Http::response(['status' => 'ok'], 200),
        ]);

        ['token' => $token] = $this->issueApplicationToken([
            'id' => 'workflow-app',
            'tenant_id' => 'workflow-tenant',
            'permissions' => ['reads'],
        ]);
        $auth = ['Authorization' => 'Bearer '.$token];

        $this->withHeaders($auth)
            ->postJson('/api/heaps', [
                'id' => 'workflow-heap',
                'name' => 'Workflow Heap',
                'metadata' => ['course' => 'design'],
            ])->assertCreated()
            ->assertJsonPath('heap_id', 'workflow-heap')
            ->assertJsonPath('owner_app', 'workflow-app')
            ->assertJsonPath('metadata.course', 'design');

        $this->withHeaders($auth)
            ->postJson('/api/heaps/workflow-heap/documents', [
                'document_id' => 'doc-workflow-1',
                'content' => 'Workflow design guidance',
                'metadata' => ['topic' => 'studio'],
                'source_url' => 'https://example.test/workflow-1',
            ])->assertCreated()
            ->assertJsonPath('document_id', 'doc-workflow-1')
            ->assertJsonPath('heap_id', 'workflow-heap')
            ->assertJsonPath('metadata.topic', 'studio');

        $this->withHeaders($auth)
            ->postJson('/api/heaps/workflow-heap/documents', [
                'document_id' => 'doc-workflow-2',
                'content' => 'Second workflow note',
                'metadata' => ['topic' => 'review'],
                'source_url' => 'https://example.test/workflow-2',
            ])->assertCreated()
            ->assertJsonPath('document_id', 'doc-workflow-2');

        $this->assertDatabaseHas('documents', [
            'id' => 'doc-workflow-1',
            'dataset_id' => 'workflow-heap',
        ]);
        $this->assertSame('workflow-heap', Document::query()->findOrFail('doc-workflow-1')->metadata_json['__rawki']['audit']['heap']);

        $this->withHeaders($auth)
            ->postJson('/api/search', [
                'query' => 'workflow design',
                'limit' => 7,
                'filters' => ['course', 'design'],
            ])->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('results.0.document_id', 'doc-workflow-1')
            ->assertJsonPath('results.0.heap_id', 'workflow-heap')
            ->assertJsonPath('results.0.chunk_content', 'Workflow design guidance')
            ->assertJsonPath('results.0.metadata.heap', 'workflow-heap')
            ->assertJsonMissingPath('query')
            ->assertJsonMissingPath('count')
            ->assertJsonMissingPath('hits')
            ->assertJsonMissingPath('auth_context');

        Http::assertSent(function (ClientRequest $request): bool {
            if ($request->url() !== 'http://bridge.test/query') {
                return false;
            }

            $payload = $request->data();

            return $this->payloadHasOnlyBridgeKeys($payload)
                && $payload['query'] === 'workflow design'
                && $payload['limit'] === 7
                && $this->filterContains($payload['filters'] ?? [], 'owner_app', 'workflow-app')
                && $this->filterContains($payload['filters'] ?? [], 'course', 'design');
        });
    }

    public function test_protect_grant_search_and_unprotect_workflow_uses_native_auth_model(): void
    {
        config()->set('authz.enabled', true);
        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');
        config()->set('config.qdrant_http_url', 'http://qdrant.test');
        Http::fake([
            'http://bridge.test/ingest' => Http::response(['ok' => true], 200),
            'http://bridge.test/query' => Http::response(['ok' => true, 'count' => 0, 'hits' => []], 200),
            'http://qdrant.test/*' => Http::response(['status' => 'ok'], 200),
        ]);

        ['token' => $token] = $this->issueApplicationToken([
            'id' => 'workflow-app',
            'tenant_id' => 'workflow-tenant',
            'permissions' => ['reads'],
        ]);
        $auth = ['Authorization' => 'Bearer '.$token];

        $this->withHeaders($auth)
            ->postJson('/api/heaps', [
                'id' => 'protected-workflow',
                'name' => 'Protected Workflow',
                'metadata' => ['course' => 'restricted'],
            ])->assertCreated()
            ->assertJsonPath('protected', false);

        $this->withHeaders($auth)
            ->postJson('/api/heaps/protected-workflow/documents', [
                'document_id' => 'doc-protected-workflow',
                'content' => 'Protected workflow body',
                'metadata' => ['topic' => 'authorization'],
            ])->assertCreated();

        $groupId = $this->withHeaders($auth)
            ->postJson('/api/auth/groups', [
                'id' => 'designers',
                'name' => 'Designers',
            ])->assertCreated()
            ->json('id');

        $this->withHeaders($auth)
            ->putJson('/api/auth/groups/'.$groupId.'/users', [
                'users' => ['learner-group'],
            ])->assertOk()
            ->assertJsonPath('data.0', 'learner-group');

        $this->withHeaders($auth)
            ->putJson('/api/auth/heaps/protected-workflow', [
                'users' => ['learner-direct'],
                'groups' => [$groupId],
            ])->assertCreated()
            ->assertJsonPath('heap_id', 'protected-workflow')
            ->assertJsonPath('protected', true)
            ->assertJsonPath('grants.users.0', 'learner-direct')
            ->assertJsonPath('grants.groups.0', $groupId);

        $this->withHeaders($auth)
            ->postJson('/api/search', [
                'query' => 'authorized direct',
                'user_identifier' => 'learner-direct',
            ])->assertOk();

        $this->withHeaders($auth)
            ->postJson('/api/search', [
                'query' => 'authorized group',
                'user_identifier' => 'learner-group',
            ])->assertOk();

        $this->withHeaders($auth)
            ->postJson('/api/search', [
                'query' => 'unauthorized protected',
                'user_identifier' => 'stranger',
            ])->assertOk();

        $this->withHeaders($auth)
            ->delete('/api/auth/heaps/protected-workflow')
            ->assertNoContent();

        $this->withHeaders($auth)
            ->getJson('/api/heaps/protected-workflow')
            ->assertOk()
            ->assertJsonPath('protected', false);

        $this->withHeaders($auth)
            ->postJson('/api/search', [
                'query' => 'after unprotect',
                'user_identifier' => 'learner-direct',
            ])->assertOk();

        Http::assertSent(fn (ClientRequest $request): bool => $this->searchRequestHas($request, 'authorized direct', function (array $filters): bool {
            return $this->filterContains($filters, 'protected', true)
                && $this->filterContains($filters, 'heap', 'protected-workflow');
        }));

        Http::assertSent(fn (ClientRequest $request): bool => $this->searchRequestHas($request, 'authorized group', function (array $filters): bool {
            return $this->filterContains($filters, 'protected', true)
                && $this->filterContains($filters, 'heap', 'protected-workflow');
        }));

        Http::assertSent(fn (ClientRequest $request): bool => $this->searchRequestHas($request, 'unauthorized protected', function (array $filters): bool {
            return $this->filterContains($filters, 'protected', false)
                && ! $this->filterContains($filters, 'protected', true);
        }));

        Http::assertSent(fn (ClientRequest $request): bool => $this->searchRequestHas($request, 'after unprotect', function (array $filters): bool {
            return $this->filterContains($filters, 'protected', false)
                && ! $this->filterContains($filters, 'protected', true);
        }));
    }

    public function test_document_move_between_heaps_uses_v2_update_route_and_rebuilds_search_payload(): void
    {
        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');
        config()->set('config.qdrant_http_url', 'http://qdrant.test');
        Http::fake([
            'http://bridge.test/ingest' => Http::response(['ok' => true], 200),
            'http://qdrant.test/*' => Http::response(['status' => 'ok'], 200),
        ]);

        ['token' => $token] = $this->issueApplicationToken([
            'id' => 'workflow-app',
            'tenant_id' => 'workflow-tenant',
            'permissions' => ['reads'],
        ]);
        $auth = ['Authorization' => 'Bearer '.$token];

        $this->withHeaders($auth)
            ->postJson('/api/heaps', [
                'id' => 'workflow-source',
                'name' => 'Workflow Source',
                'metadata' => ['course' => 'source'],
            ])->assertCreated();

        $this->withHeaders($auth)
            ->postJson('/api/heaps', [
                'id' => 'workflow-target',
                'name' => 'Workflow Target',
                'visibility' => 'hidden',
                'metadata' => ['course' => 'target'],
            ])->assertCreated();

        $this->withHeaders($auth)
            ->postJson('/api/heaps/workflow-source/documents', [
                'document_id' => 'doc-move-workflow',
                'content' => 'Move me between heaps',
                'metadata' => ['topic' => 'migration'],
            ])->assertCreated()
            ->assertJsonPath('heap_id', 'workflow-source');

        Http::fake([
            'http://qdrant.test/*' => Http::response(['status' => 'ok'], 200),
        ]);

        $this->withHeaders($auth)
            ->putJson('/api/documents/doc-move-workflow', [
                'heap_id' => 'workflow-target',
            ])->assertOk()
            ->assertJsonPath('document_id', 'doc-move-workflow')
            ->assertJsonPath('heap_id', 'workflow-target')
            ->assertJsonPath('metadata.topic', 'migration');

        $document = Document::query()->findOrFail('doc-move-workflow');
        $this->assertSame('workflow-target', $document->heapId());
        $this->assertSame('hawki_workflow_target', $document->collection);
        $this->assertSame('workflow-target', $document->metadata_json['__rawki']['audit']['heap']);
        $this->assertSame('migration', $document->metadata_json['topic']);

        Http::assertSent(function (ClientRequest $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'http://qdrant.test/collections/hawki_workflow_source/points/payload/delete'
                && ($request['filter']['must'][0]['match']['value'] ?? null) === 'doc-move-workflow';
        });

        Http::assertSent(function (ClientRequest $request): bool {
            $payload = $request['payload'] ?? [];

            return $request->method() === 'POST'
                && $request->url() === 'http://qdrant.test/collections/hawki_workflow_target/points/payload'
                && ($request['filter']['must'][0]['match']['value'] ?? null) === 'doc-move-workflow'
                && ($payload['heap'] ?? null) === 'workflow-target'
                && ($payload['course'] ?? null) === 'target'
                && ($payload['topic'] ?? null) === 'migration'
                && ($payload['visibility'] ?? null) === 'hidden';
        });
    }

    /**
     * @param callable(array<mixed>): bool $assertion
     */
    private function searchRequestHas(ClientRequest $request, string $query, callable $assertion): bool
    {
        if ($request->url() !== 'http://bridge.test/query') {
            return false;
        }

        $payload = $request->data();
        if (($payload['query'] ?? null) !== $query || ! $this->payloadHasOnlyBridgeKeys($payload)) {
            return false;
        }

        $filters = $payload['filters'] ?? [];

        return is_array($filters) && $assertion($filters);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadHasOnlyBridgeKeys(array $payload): bool
    {
        $keys = array_keys($payload);
        sort($keys);

        return $keys === ['filters', 'limit', 'query'];
    }

    /**
     * @param array<mixed> $filter
     */
    private function filterContains(array $filter, string $key, mixed $value): bool
    {
        if ($this->isFilterLeaf($filter)) {
            return $filter[0] === $key && $this->filterValueMatches($filter[1], $value);
        }

        if (array_key_exists($key, $filter)) {
            return $this->filterValueMatches($filter[$key], $value);
        }

        if ($this->isFilterOperator($filter, 'AND') || $this->isFilterOperator($filter, 'OR')) {
            $children = is_array($filter[1] ?? null) ? $filter[1] : [];
            foreach ($children as $child) {
                if (is_array($child) && $this->filterContains($child, $key, $value)) {
                    return true;
                }
            }
        }

        if ($this->isFilterOperator($filter, 'NOT') && is_array($filter[1] ?? null) && $this->filterContains($filter[1], $key, $value)) {
            return true;
        }

        foreach ($filter as $candidate) {
            if (is_array($candidate) && $this->filterContains($candidate, $key, $value)) {
                return true;
            }
        }

        return false;
    }

    private function isFilterLeaf(array $filter): bool
    {
        return array_is_list($filter)
            && count($filter) === 2
            && is_string($filter[0] ?? null)
            && ! in_array(strtoupper($filter[0]), ['AND', 'OR', 'NOT'], true);
    }

    private function isFilterOperator(array $filter, string $operator): bool
    {
        return array_is_list($filter)
            && count($filter) === 2
            && is_string($filter[0] ?? null)
            && strtoupper($filter[0]) === $operator;
    }

    private function filterValueMatches(mixed $candidate, mixed $value): bool
    {
        if (is_array($candidate)) {
            return in_array($value, $candidate, true);
        }

        return $candidate === $value;
    }
}
