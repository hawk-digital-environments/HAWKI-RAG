<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Document;
use App\Models\SpecV2\Group;
use App\Models\SpecV2\GroupMember;
use App\Models\User;
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
                'top_k' => 3,
                'user_identifier' => 'learner-123',
                'preferred_tags' => ['policy'],
            ])->assertOk();

        Http::assertSent(function (Request $request) use ($publicDocument, $protectedDocument): bool {
            if ($request->url() !== 'http://bridge.test/query') {
                return false;
            }

            $filters = $request->data()['filters'] ?? [];
            $docIds = array_map(
                static fn (array $match): ?string => $match['match']['value'] ?? null,
                is_array($filters['should'] ?? null) ? $filters['should'] : [],
            );
            sort($docIds);
            $expected = [$protectedDocument->id, $publicDocument->id];
            sort($expected);

            return $request->data()['top_k'] === 3
                && $request->data()['preferred_tags'] === ['policy']
                && ($request->data()['auth_context'] ?? null) === null
                && $docIds === $expected;
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
            $docIds = array_map(
                static fn (array $match): ?string => $match['match']['value'] ?? null,
                is_array($filters['should'] ?? null) ? $filters['should'] : [],
            );

            return $request->url() === 'http://bridge.test/query'
                && $docIds === ['__rawki_no_match__'];
        });
    }
}
