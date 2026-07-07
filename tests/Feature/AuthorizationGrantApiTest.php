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

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/auth/heaps/heap-protected', [
            'users' => ['direct-reader@hawk.de'],
            'groups' => [$application->id.':design_students'],
        ])->assertCreated()
            ->assertJsonPath('heap_id', 'heap-protected')
            ->assertJsonPath('grants.groups.0', $application->id.':design_students')
            ->assertJsonPath('grants.users.0', 'direct-reader@hawk.de');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/check?user_identifier='.urlencode('direct-reader@hawk.de').'&heap_id=heap-protected')
            ->assertOk()
            ->assertJsonPath('permitted', true);

        $this->assertDatabaseHas('user_identities', [
            'tenant_id' => 'uni-hawk',
            'application_id' => $application->id,
            'external_user_id' => 'direct-reader@hawk.de',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/search', [
                'query' => 'protected',
                'user_identifier' => 'learner-123',
            ])->assertOk();

        Http::assertSent(function (Request $request) use ($document): bool {
            $filters = $request->data()['filters'] ?? [];

            return $request->url() === 'http://bridge.test/query'
                && $this->payloadHasOnlyBridgeKeys($request->data())
                && $request->data()['limit'] === 5
                && $this->filterContains($filters, 'owner_app', 'hawki-web')
                && $this->filterContains($filters, 'protected', true)
                && $this->filterContains($filters, 'heap', 'heap-protected')
                && ! $this->filterContainsDocumentId($filters, (string) $document->id);
        });
    }

    public function test_document_grant_api_allows_auth_check_for_direct_user_grants(): void
    {
        config()->set('authz.enabled', true);
        ['token' => $token] = $this->issueApplicationToken([
            'id' => 'rawki-default',
            'tenant_id' => 'default',
            'permissions' => ['reads'],
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

        $userIdentifier = 'reader@example.test';
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/auth/documents/'.$document->id, [
            'users' => [$userIdentifier],
        ])->assertCreated()
            ->assertJsonPath('document_id', (string) $document->id)
            ->assertJsonPath('grants.users.0', $userIdentifier)
            ->assertJsonMissingPath('grants.groups');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/check?user_identifier='.urlencode($userIdentifier).'&document_id='.urlencode((string) $document->id))
            ->assertOk()
            ->assertJsonPath('permitted', true);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/auth/documents/'.$document->id, [
                'groups' => ['not-valid-for-documents'],
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['users', 'groups']);

        $this->assertDatabaseHas('user_identities', [
            'tenant_id' => 'default',
            'application_id' => 'rawki-default',
            'external_user_id' => $userIdentifier,
        ]);
    }

    public function test_auth_group_aliases_and_utilities_follow_the_spec_surface(): void
    {
        config()->set('authz.enabled', true);

        ['application' => $application, 'token' => $token] = $this->issueApplicationToken([
            'id' => 'hawki-web',
            'tenant_id' => 'uni-hawk',
            'permissions' => ['reads-all-apps'],
        ]);

        Dataset::query()->create([
            'dataset_id' => 'heap-utility',
            'tenant_id' => 'uni-hawk',
            'owner_application_id' => $application->id,
            'name' => 'Utility Heap',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => true,
            'metadata_json' => [],
            'qdrant_collection' => 'utility_heap',
            'neo4j_namespace' => 'utility_heap',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/groups', [
                'id' => 'design_students',
                'name' => 'Design Students',
                'owner_application_id' => $application->id,
            ])->assertCreated()
            ->assertJsonPath('id', $application->id.':design_students');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/auth/groups/'.$application->id.':design_students/users', [
                'users' => ['alice@hawk.de'],
            ])->assertOk()
            ->assertJsonPath('data.0', 'alice@hawk.de');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/auth/heaps/heap-utility', [
                'groups' => [$application->id.':design_students'],
            ])->assertCreated()
            ->assertJsonPath('heap_id', 'heap-utility')
            ->assertJsonPath('grants.groups.0', $application->id.':design_students')
            ->assertJsonPath('protected', true);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/groups/'.$application->id.':design_students')
            ->assertOk()
            ->assertJsonPath('assigned_heaps.0', 'heap-utility');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/check?user_identifier='.urlencode('alice@hawk.de').'&heap_id=heap-utility')
            ->assertOk()
            ->assertJsonPath('permitted', true);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/users/by-identifier/heaps?identifier='.urlencode('alice@hawk.de'))
            ->assertOk()
            ->assertJsonPath('data.0', 'heap-utility')
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_grant_replacement_lifecycle_statuses_and_unprotect_flow(): void
    {
        config()->set('authz.enabled', true);
        config()->set('config.qdrant_http_url', 'http://qdrant.test');
        Http::fake([
            'http://qdrant.test/*' => Http::response(['status' => 'ok'], 200),
        ]);

        ['token' => $token] = $this->issueApplicationToken([
            'id' => 'rawki-default',
            'tenant_id' => 'default',
            'permissions' => ['reads'],
        ]);

        Dataset::query()->create([
            'dataset_id' => 'heap-lifecycle',
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
            'name' => 'Grant Lifecycle',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => [],
            'qdrant_collection' => 'heap_lifecycle',
            'neo4j_namespace' => 'heap_lifecycle',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $document = Document::query()->create([
            'dataset_id' => 'heap-lifecycle',
            'collection' => 'heap_lifecycle',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/grant-lifecycle',
            'storage_path' => '/tmp/grant-lifecycle.md',
            'checksum_sha256' => hash('sha256', 'grant-lifecycle'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => [],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/auth/heaps/heap-lifecycle', [
                'users' => ['first@example.test'],
            ])->assertCreated()
            ->assertJsonPath('heap_id', 'heap-lifecycle')
            ->assertJsonPath('protected', true)
            ->assertJsonPath('grants.users.0', 'first@example.test');

        $this->assertDatabaseHas('datasets', [
            'dataset_id' => 'heap-lifecycle',
            'protected' => true,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/auth/heaps/heap-lifecycle', [
                'users' => ['second@example.test'],
            ])->assertOk()
            ->assertJsonPath('grants.users.0', 'second@example.test');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/auth/heaps/heap-lifecycle')
            ->assertNoContent();

        $this->assertDatabaseCount('heap_grants', 0);
        $this->assertDatabaseHas('datasets', [
            'dataset_id' => 'heap-lifecycle',
            'protected' => false,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/auth/documents/'.$document->id, [
                'users' => ['reader@example.test'],
            ])->assertCreated()
            ->assertJsonPath('document_id', (string) $document->id)
            ->assertJsonPath('grants.users.0', 'reader@example.test');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/auth/documents/'.$document->id, [
                'users' => ['replacement@example.test'],
            ])->assertOk()
            ->assertJsonPath('grants.users.0', 'replacement@example.test');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/auth/documents/'.$document->id)
            ->assertNoContent();

        $this->assertDatabaseCount('document_grants', 0);
    }

    public function test_grant_read_paths_use_403_for_scope_denials_and_404_for_missing_resources(): void
    {
        config()->set('authz.enabled', true);

        ['application' => $ownedApplication] = $this->issueApplicationToken([
            'id' => 'app-owned',
            'tenant_id' => 'tenant-a',
            'permissions' => ['reads'],
        ]);
        ['application' => $peerApplication] = $this->issueApplicationToken([
            'id' => 'app-peer',
            'tenant_id' => 'tenant-a',
            'permissions' => ['reads'],
        ]);
        ['token' => $token] = $this->issueApplicationToken([
            'id' => 'app-owned',
            'tenant_id' => 'tenant-a',
            'permissions' => ['reads'],
        ]);

        Dataset::query()->create([
            'dataset_id' => 'heap-owned',
            'tenant_id' => 'tenant-a',
            'owner_application_id' => $ownedApplication->id,
            'name' => 'Owned Heap',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => [],
            'qdrant_collection' => 'owned_heap',
            'neo4j_namespace' => 'owned_heap',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Dataset::query()->create([
            'dataset_id' => 'heap-peer',
            'tenant_id' => 'tenant-a',
            'owner_application_id' => $peerApplication->id,
            'name' => 'Peer Heap',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => false,
            'metadata_json' => [],
            'qdrant_collection' => 'peer_heap',
            'neo4j_namespace' => 'peer_heap',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Document::query()->create([
            'id' => 'doc-owned',
            'dataset_id' => 'heap-owned',
            'collection' => 'owned_heap',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/owned',
            'storage_path' => '/tmp/owned.md',
            'checksum_sha256' => hash('sha256', 'owned'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => [],
        ]);
        Document::query()->create([
            'id' => 'doc-peer',
            'dataset_id' => 'heap-peer',
            'collection' => 'peer_heap',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/peer',
            'storage_path' => '/tmp/peer.md',
            'checksum_sha256' => hash('sha256', 'peer'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => [],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/heaps/heap-peer')
            ->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/heaps/heap-missing')
            ->assertNotFound();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/documents/doc-peer')
            ->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/documents/doc-missing')
            ->assertNotFound();
    }

    public function test_heap_metadata_rejects_reserved_keys(): void
    {
        $this->actingAsApplication([
            'id' => 'rawki-default',
            'tenant_id' => 'default',
        ]);

        $this->postJson('/api/heaps', [
            'id' => 'heap-invalid',
            'name' => 'Invalid Heap',
            'metadata' => [
                'protected' => true,
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['metadata']);

        $this->postJson('/api/heaps', [
            'id' => 'heap-invalid-internal',
            'name' => 'Invalid Internal Heap',
            'metadata' => [
                '__rawki' => ['audit' => ['schema' => 999]],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['metadata']);

        $this->postJson('/api/heaps', [
            'id' => 'heap-valid',
            'name' => 'Valid Heap',
            'metadata' => [
                'topic' => 'design',
            ],
        ])->assertCreated();

        $this->patchJson('/api/heaps/heap-valid', [
            'metadata' => [
                '__rawki' => ['audit' => ['schema' => 999]],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['metadata']);
    }

    public function test_document_metadata_rejects_reserved_keys_on_create_and_update(): void
    {
        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');
        Http::fake([
            'http://bridge.test/ingest' => Http::response(['ok' => true], 200),
        ]);

        ['token' => $token] = $this->issueApplicationToken([
            'id' => 'rawki-default',
            'tenant_id' => 'default',
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/heaps', [
                'id' => 'heap-reserved-docs',
                'name' => 'Reserved Docs',
            ])->assertCreated();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/heaps/heap-reserved-docs/documents', [
                'document_id' => 'doc-reserved-create',
                'content' => 'forbidden key',
                'metadata' => ['visibility' => true],
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['metadata']);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/heaps/heap-reserved-docs/documents', [
                'document_id' => 'doc-reserved-internal',
                'content' => 'forbidden internal key',
                'metadata' => ['__rawki' => ['audit' => ['schema' => 999]]],
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['metadata']);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/heaps/heap-reserved-docs/documents', [
                'document_id' => 'doc-reserved-update',
                'content' => 'allowed key',
                'metadata' => ['topic' => 'design'],
            ])->assertCreated();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->putJson('/api/documents/doc-reserved-update', [
                'metadata' => ['protected' => true],
            ])->assertStatus(422)
            ->assertJsonValidationErrors(['metadata']);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->putJson('/api/documents/doc-reserved-update', [
                'metadata' => ['__rawki' => ['search_payload' => ['heap' => 'forged']]],
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
            ->postJson('/api/search', [
                'query' => 'design',
                'filters' => [
                    'AND',
                    [
                        ['heap', 'heap-design'],
                        ['visibility', 'hidden'],
                        ['course', 'design'],
                    ],
                ],
            ])->assertOk();

        Http::assertSent(function (Request $request) use ($matching): bool {
            $filters = $request->data()['filters'] ?? [];

            return $request->url() === 'http://bridge.test/query'
                && $this->payloadHasOnlyBridgeKeys($request->data())
                && $this->filterContains($filters, 'owner_app', 'hawki-web')
                && $this->filterContains($filters, 'heap', 'heap-design')
                && $this->filterContains($filters, 'visibility', 'hidden')
                && $this->filterContains($filters, 'course', 'design')
                && ! $this->filterContainsDocumentId($filters, (string) $matching->id);
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

        $this->actingAsApplication([
            'id' => 'rawki-default',
            'tenant_id' => 'default',
        ]);

        $this->putJson('/api/auth/heaps/heap-disabled-authz', [
            'groups' => ['missing-group'],
        ])->assertOk()
            ->assertJsonPath('heap_id', 'heap-disabled-authz')
            ->assertJsonPath('grants.groups', [])
            ->assertJsonPath('grants.users', []);

        $this->putJson('/api/auth/documents/'.$document->id, [
            'users' => ['reader@example.test'],
        ])->assertOk()
            ->assertJsonPath('document_id', (string) $document->id)
            ->assertJsonPath('grants.users', [])
            ->assertJsonMissingPath('grants.groups');

        $this->getJson('/api/auth/check?user_identifier='.urlencode('reader@example.test').'&heap_id=heap-disabled-authz')
            ->assertOk()
            ->assertJsonPath('permitted', true);

        $this->getJson('/api/auth/users/by-identifier/heaps?identifier='.urlencode('reader@example.test'))
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('pagination.total', 0);

        $this->deleteJson('/api/auth/heaps/heap-disabled-authz')
            ->assertNoContent();
        $this->deleteJson('/api/auth/documents/'.$document->id)
            ->assertNoContent();

        $this->assertDatabaseCount('heap_grants', 0);
        $this->assertDatabaseCount('document_grants', 0);
        $this->assertDatabaseHas('datasets', [
            'dataset_id' => 'heap-disabled-authz',
            'protected' => false,
        ]);
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
            $candidate = $filter[$key];

            return $this->filterValueMatches($candidate, $value);
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
