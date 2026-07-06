<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\Document;
use App\Models\SpecV2\Group;
use App\Models\SpecV2\GroupMember;
use App\Services\Authorization\IdentityProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthorizationGrantApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_heap_grant_api_makes_protected_heap_searchable_for_group_member(): void
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
            'dataset_id' => 'heap-protected',
            'tenant_id' => 'uni-hawk',
            'owner_application_id' => $application->id,
            'name' => 'Protected Heap',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => true,
            'metadata_json' => ['course' => 'design'],
            'qdrant_collection' => 'protected_heap',
            'neo4j_namespace' => 'protected_heap',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $document = Document::query()->create([
            'dataset_id' => 'heap-protected',
            'collection' => 'protected_heap',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/protected',
            'storage_path' => '/tmp/protected.md',
            'checksum_sha256' => hash('sha256', 'protected'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => [],
        ]);

        $assignments = app(IdentityProvisioningService::class)->groupMemberAssignments(
            'uni-hawk',
            $application->id,
            ['learner-123'],
        );

        Group::query()->create([
            'id' => $application->id.':design_students',
            'tenant_id' => 'uni-hawk',
            'owner_application_id' => $application->id,
            'name' => 'Design Students',
            'metadata_json' => [],
        ]);
        GroupMember::query()->create([
            'group_id' => $application->id.':design_students',
            'user_identifier' => 'learner-123',
            'internal_user_id' => $assignments[0]->internalUserId,
        ]);

        $this->actingAsApiUser();
        $this->putJson('/api/auth/heaps/heap-protected/grants', [
            'groups' => [$application->id.':design_students'],
        ])->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('grants.0.groupId', $application->id.':design_students');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/query', [
                'query' => 'protected',
                'user_identifier' => 'learner-123',
            ])->assertOk();

        Http::assertSent(function (Request $request) use ($document): bool {
            $filters = $request->data()['filters'] ?? [];
            $docIds = array_map(
                static fn (array $match): ?string => $match['match']['value'] ?? null,
                is_array($filters['should'] ?? null) ? $filters['should'] : [],
            );

            return $request->url() === 'http://bridge.test/query'
                && $docIds === [$document->id];
        });
    }

    public function test_document_grant_api_allows_document_browser_when_graph_denies(): void
    {
        config()->set('authz.enabled', true);
        config()->set('authz.document_api_enforced', true);
        config()->set('authz.graph.backend', 'spicedb');
        config()->set('authz.graph.spicedb.api_url', 'http://spicedb.test');
        config()->set('authz.graph.spicedb.preshared_key', 'secret-token');
        Http::fake([
            'http://spicedb.test/v1/permissions/checkbulk' => Http::response([
                'pairs' => [['item' => ['permissionship' => 'PERMISSIONSHIP_NO_PERMISSION']]],
            ]),
        ]);

        Dataset::query()->create([
            'dataset_id' => 'grant-dataset',
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
            'name' => 'Grant Dataset',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => true,
            'metadata_json' => [],
            'qdrant_collection' => 'grant_dataset',
            'neo4j_namespace' => 'grant_dataset',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $document = Document::query()->create([
            'dataset_id' => 'grant-dataset',
            'collection' => 'grant_dataset',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/granted',
            'storage_path' => '/tmp/granted.md',
            'checksum_sha256' => hash('sha256', 'granted'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => [],
        ]);

        $user = $this->actingAsApiUser();
        $identity = app(IdentityProvisioningService::class)->actorForUser($user);

        Group::query()->create([
            'id' => 'rawki-default:direct_doc_readers',
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
            'name' => 'Direct Doc Readers',
            'metadata_json' => [],
        ]);
        GroupMember::query()->create([
            'group_id' => 'rawki-default:direct_doc_readers',
            'user_identifier' => $user->email,
            'internal_user_id' => $identity?->internal_user_id,
        ]);

        $this->putJson('/api/auth/documents/'.$document->id.'/grants', [
            'groups' => ['rawki-default:direct_doc_readers'],
        ])->assertOk()
            ->assertJsonPath('count', 1);

        $this->getJson('/api/documents/'.$document->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('document.id', $document->id);
    }

    public function test_heap_metadata_rejects_reserved_keys(): void
    {
        $this->actingAsApiUser();

        $this->postJson('/api/heaps', [
            'id' => 'heap-invalid',
            'name' => 'Invalid Heap',
            'metadata' => [
                'protected' => true,
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['metadata']);
    }

    public function test_query_filter_language_filters_by_reserved_fields_and_metadata(): void
    {
        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');
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
            'dataset_id' => 'heap-design',
            'tenant_id' => 'uni-hawk',
            'owner_application_id' => $application->id,
            'name' => 'Design Heap',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_HIDDEN,
            'protected' => false,
            'metadata_json' => ['course' => 'design'],
            'qdrant_collection' => 'heap_design',
            'neo4j_namespace' => 'heap_design',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Dataset::query()->create([
            'dataset_id' => 'heap-policy',
            'tenant_id' => 'uni-hawk',
            'owner_application_id' => $application->id,
            'name' => 'Policy Heap',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => ['course' => 'policy'],
            'qdrant_collection' => 'heap_policy',
            'neo4j_namespace' => 'heap_policy',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $matching = Document::query()->create([
            'dataset_id' => 'heap-design',
            'collection' => 'heap_design',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/design',
            'storage_path' => '/tmp/design.md',
            'checksum_sha256' => hash('sha256', 'design'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => ['topic' => 'ux'],
        ]);

        Document::query()->create([
            'dataset_id' => 'heap-policy',
            'collection' => 'heap_policy',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/policy',
            'storage_path' => '/tmp/policy.md',
            'checksum_sha256' => hash('sha256', 'policy'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => ['topic' => 'ux'],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/query', [
                'query' => 'design',
                'filters' => [
                    'AND' => [
                        ['heap' => 'heap-design'],
                        ['visibility' => 'hidden'],
                        ['course' => 'design'],
                    ],
                ],
            ])->assertOk();

        Http::assertSent(function (Request $request) use ($matching): bool {
            $filters = $request->data()['filters'] ?? [];
            $docIds = array_map(
                static fn (array $match): ?string => $match['match']['value'] ?? null,
                is_array($filters['should'] ?? null) ? $filters['should'] : [],
            );

            return $request->url() === 'http://bridge.test/query'
                && $docIds === [$matching->id];
        });
    }

    public function test_grant_endpoints_ignore_inputs_without_side_effects_when_authorization_is_disabled(): void
    {
        config()->set('authz.enabled', false);

        Dataset::query()->create([
            'dataset_id' => 'heap-disabled-authz',
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
            'name' => 'Disabled Auth Heap',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => [],
            'qdrant_collection' => 'disabled_auth_heap',
            'neo4j_namespace' => 'disabled_auth_heap',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $document = Document::query()->create([
            'dataset_id' => 'heap-disabled-authz',
            'collection' => 'disabled_auth_heap',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/disabled-auth',
            'storage_path' => '/tmp/disabled-auth.md',
            'checksum_sha256' => hash('sha256', 'disabled-auth'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => [],
        ]);

        $this->actingAsApiUser();

        $this->putJson('/api/auth/heaps/heap-disabled-authz/grants', [
            'groups' => ['missing-group'],
        ])->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('grants', []);

        $this->putJson('/api/auth/documents/'.$document->id.'/grants', [
            'groups' => ['missing-group'],
        ])->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('grants', []);

        $this->assertDatabaseCount('heap_grants', 0);
        $this->assertDatabaseCount('document_grants', 0);
    }
}
