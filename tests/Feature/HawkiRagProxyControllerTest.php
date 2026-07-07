<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Document;
use App\Models\SpecV2\Group;
use App\Models\SpecV2\GroupMember;
use App\Services\Authorization\IdentityProvisioningService;
use App\Services\SpecV2\Repositories\DocumentGrantRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HawkiRagProxyControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_forwards_gateway_filters_to_bridge_for_application_tokens(): void
    {
        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');
        config()->set('authz.enabled', true);
        Http::fake([
            'http://bridge.test/query' => Http::response([
                'ok' => true,
                'count' => 0,
                'hits' => [],
            ], 200),
        ]);

        ['application' => $application, 'token' => $token] = $this->issueApplicationToken([
            'id' => 'hawki-web',
            'tenant_id' => 'uni-hawk',
            'permissions' => ['reads'],
        ]);

        Dataset::query()->create([
            'dataset_id' => 'heap-public',
            'tenant_id' => 'uni-hawk',
            'owner_application_id' => $application->id,
            'name' => 'Public Heap',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => [],
            'qdrant_collection' => 'hawki_heap_public',
            'neo4j_namespace' => 'hawki_heap_public',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Dataset::query()->create([
            'dataset_id' => 'heap-protected',
            'tenant_id' => 'uni-hawk',
            'owner_application_id' => $application->id,
            'name' => 'Protected Heap',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => true,
            'metadata_json' => [],
            'qdrant_collection' => 'hawki_heap_protected',
            'neo4j_namespace' => 'hawki_heap_protected',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $publicDocument = Document::query()->create([
            'dataset_id' => 'heap-public',
            'collection' => 'hawki_heap_public',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/public',
            'storage_path' => '/tmp/public.md',
            'checksum_sha256' => hash('sha256', 'public'),
            'status' => Document::STATUS_COMPLETED,
        ]);

        $protectedDocument = Document::query()->create([
            'dataset_id' => 'heap-protected',
            'collection' => 'hawki_heap_protected',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/protected',
            'storage_path' => '/tmp/protected.md',
            'checksum_sha256' => hash('sha256', 'protected'),
            'status' => Document::STATUS_COMPLETED,
        ]);

        $assignments = app(IdentityProvisioningService::class)->groupMemberAssignments(
            'uni-hawk',
            $application->id,
            ['learner-123'],
        );
        Group::query()->create([
            'id' => $application->id.':course_design',
            'tenant_id' => 'uni-hawk',
            'owner_application_id' => $application->id,
            'name' => 'Course Design',
            'metadata_json' => [],
        ]);
        GroupMember::query()->create([
            'group_id' => $application->id.':course_design',
            'user_identifier' => 'learner-123',
            'internal_user_id' => $assignments[0]->internalUserId,
        ]);
        app(DocumentGrantRepository::class)->add((string) $protectedDocument->id, [$application->id.':course_design']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/search', [
                'query' => 'campus policy',
                'limit' => 3,
                'user_identifier' => 'learner-123',
                'preferred_tags' => ['policy'],
            ])->assertOk();

        Http::assertSent(function (Request $request) use ($publicDocument, $protectedDocument): bool {
            if ($request->url() !== 'http://bridge.test/query') {
                return false;
            }

            $filters = $request->data()['filters'] ?? [];
            return $this->payloadHasOnlyBridgeKeys($request->data())
                && $request->data()['query'] === 'campus policy'
                && $request->data()['limit'] === 3
                && $this->filterContains($filters, 'owner_app', 'hawki-web')
                && $this->filterContains($filters, 'protected', false)
                && $this->filterContainsDocumentId($filters, (string) $protectedDocument->id)
                && ! $this->filterContainsDocumentId($filters, (string) $publicDocument->id);
        });
    }

    public function test_query_for_application_without_read_permission_scopes_to_no_match(): void
    {
        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');
        Http::fake([
            'http://bridge.test/query' => Http::response([
                'ok' => true,
                'count' => 0,
                'hits' => [],
            ], 200),
        ]);

        ['token' => $token] = $this->issueApplicationToken([
            'id' => 'decorative-app',
            'tenant_id' => 'uni-hawk',
            'permissions' => [],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/search', [
                'query' => 'campus policy',
            ])->assertOk();

        Http::assertSent(function (Request $request): bool {
            $filters = $request->data()['filters'] ?? [];

            return $request->url() === 'http://bridge.test/query'
                && $this->payloadHasOnlyBridgeKeys($request->data())
                && $request->data()['limit'] === 5
                && $this->filterContainsDocumentId($filters, '__rawki_no_match__');
        });
    }

    public function test_chunk_search_forwards_retrieval_only_payload_to_bridge(): void
    {
        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');
        config()->set('authz.enabled', true);
        Http::fake([
            'http://bridge.test/query' => Http::response([
                'hits' => [
                    [
                        'id' => 'chunk-1',
                        'score' => 0.9,
                        'payload' => [
                            'document_id' => 'doc-1',
                            'content' => 'Chunk body',
                        ],
                    ],
                ],
            ], 200),
        ]);

        ['token' => $token] = $this->issueApplicationToken([
            'id' => 'hawki-web',
            'tenant_id' => 'uni-hawk',
            'permissions' => ['reads'],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/search/chunks', [
                'query' => 'campus policy',
                'top_k' => 4,
                'user_identifier' => 'learner-123',
                'preferred_tags' => ['policy'],
                'filters' => ['visibility' => 'discoverable'],
            ])
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('chunks.0.id', 'chunk-1');

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'http://bridge.test/query') {
                return false;
            }

            $payload = $request->data();
            $filters = $payload['filters'] ?? [];

            return $payload['query'] === 'campus policy'
                && $this->payloadHasOnlyBridgeKeys($payload)
                && $payload['limit'] === 4
                && $this->filterContains($filters, 'owner_app', 'hawki-web')
                && $this->filterContains($filters, 'protected', false)
                && $this->filterContains($filters, 'visibility', 'discoverable');
        });
    }

    public function test_grouped_chunk_search_forwards_canonical_limit_without_authorization_payload_fields(): void
    {
        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');
        Http::fake([
            'http://bridge.test/query' => Http::response([
                'hits' => [
                    [
                        'id' => 'chunk-1',
                        'score' => 0.9,
                        'payload' => [
                            'document_id' => 'doc-1',
                            'content' => 'Chunk body',
                        ],
                    ],
                    [
                        'id' => 'chunk-2',
                        'score' => 0.8,
                        'payload' => [
                            'document_id' => 'doc-1',
                            'content' => 'Chunk body 2',
                        ],
                    ],
                ],
            ], 200),
        ]);

        ['token' => $token] = $this->issueApplicationToken([
            'id' => 'hawki-web',
            'tenant_id' => 'uni-hawk',
            'permissions' => ['reads'],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/search/chunks/grouped', [
                'query' => 'studio design',
                'limit' => 2,
                'user_identifier' => 'learner-456',
                'fast_mode' => false,
                'smart_lookup' => true,
            ])
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('groups.0.document_id', 'doc-1')
            ->assertJsonCount(2, 'groups.0.chunks');

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'http://bridge.test/query') {
                return false;
            }

            $payload = $request->data();

            return $payload['query'] === 'studio design'
                && $this->payloadHasOnlyBridgeKeys($payload)
                && $payload['limit'] === 2
                && $this->filterContains($payload['filters'] ?? [], 'owner_app', 'hawki-web');
        });
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function filterContains(array $filter, string $key, mixed $value): bool
    {
        if (array_key_exists($key, $filter)) {
            $candidate = $filter[$key];

            if (is_array($candidate)) {
                return in_array($value, $candidate, true);
            }

            return $candidate === $value;
        }

        foreach (['AND', 'OR'] as $operator) {
            $children = $filter[$operator] ?? null;
            if (! is_array($children)) {
                continue;
            }

            foreach ($children as $child) {
                if (is_array($child) && $this->filterContains($child, $key, $value)) {
                    return true;
                }
            }
        }

        $not = $filter['NOT'] ?? null;
        if (is_array($not) && $this->filterContains($not, $key, $value)) {
            return true;
        }

        foreach ($filter as $candidate) {
            if (is_array($candidate) && $this->filterContains($candidate, $key, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function filterContainsDocumentId(array $filter, string $documentId): bool
    {
        return $this->filterContains($filter, 'document_id', $documentId);
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
}
