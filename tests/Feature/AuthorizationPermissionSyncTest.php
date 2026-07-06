<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuthorizationPermissionEvent;
use App\Models\SpecV2\DocumentGrant;
use App\Models\SpecV2\Group;
use App\Models\SpecV2\GroupMember;
use App\Models\Dataset;
use App\Models\Document;
use App\Services\Authorization\PermissionSyncService;
use App\Services\Authorization\Values\LmsDocumentRelation;
use App\Services\Authorization\Values\LmsMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthorizationPermissionSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_sync_records_normalized_events_and_writes_permission_graph_relationships(): void
    {
        config()->set('authz.enabled', true);
        config()->set('authz.graph.backend', 'spicedb');
        config()->set('authz.graph.spicedb.api_url', 'http://spicedb.test');
        config()->set('authz.graph.spicedb.preshared_key', 'secret-token');
        Http::fake([
            'http://spicedb.test/v1/relationships/write' => Http::response(['written_at' => ['token' => 'zed-token']]),
        ]);

        Dataset::query()->create([
            'dataset_id' => 'grant-sync-dataset',
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
            'name' => 'Grant Sync Dataset',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => true,
            'metadata_json' => [],
            'qdrant_collection' => 'grant_sync_dataset',
            'neo4j_namespace' => 'grant_sync_dataset',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Document::query()->create([
            'id' => 'doc-1',
            'dataset_id' => 'grant-sync-dataset',
            'collection' => 'grant_sync_dataset',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/doc-1',
            'storage_path' => '/tmp/doc-1.md',
            'checksum_sha256' => hash('sha256', 'doc-1'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => [],
        ]);

        $sourceUpdatedAt = Carbon::parse('2026-07-03 12:00:00');
        $result = app(PermissionSyncService::class)->sync(
            [
                new LmsMembership('local', 'user-1', 'course-1', 'teacher', $sourceUpdatedAt),
            ],
            [
                new LmsDocumentRelation('local', 'course-1', 'doc-1', $sourceUpdatedAt),
            ],
        );

        $this->assertSame('idempotent-upsert', $result['reconciliation']['strategy']);
        $this->assertSame([
            'groups_created' => 1,
            'document_grants_created' => 1,
            'group_members_upserted' => 1,
        ], $result['native']);
        $this->assertSame([
            [
                'resource_type' => 'course',
                'resource_id' => 'local__course-1',
                'relation' => 'instructor',
                'subject_type' => 'user',
                'subject_id' => 'local__user-1',
                'subject_relation' => null,
            ],
            [
                'resource_type' => 'document',
                'resource_id' => 'doc-1',
                'relation' => 'course',
                'subject_type' => 'course',
                'subject_id' => 'local__course-1',
                'subject_relation' => null,
            ],
        ], $result['relationships']);
        $this->assertDatabaseHas('authorization_permission_events', [
            'provider' => 'local',
            'external_user_id' => 'user-1',
            'course_id' => 'course-1',
            'role' => 'teacher',
            'document_id' => null,
        ]);
        $this->assertDatabaseHas('authorization_permission_events', [
            'provider' => 'local',
            'external_user_id' => null,
            'course_id' => 'course-1',
            'role' => null,
            'document_id' => 'doc-1',
        ]);
        $group = Group::query()->first();
        $this->assertNotNull($group);
        $this->assertSame('default', $group->tenant_id);
        $this->assertSame('rawki-default', $group->owner_application_id);
        $this->assertSame('local', $group->metadata_json['projection']['provider']);
        $this->assertSame('course-1', $group->metadata_json['projection']['course_id']);
        $this->assertDatabaseHas('document_grants', [
            'document_id' => 'doc-1',
            'group_id' => $group->id,
        ]);
        $this->assertDatabaseHas('group_members', [
            'group_id' => $group->id,
            'user_identifier' => 'user-1',
        ]);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://spicedb.test/v1/relationships/write'
                && ($request->data()['updates'] ?? null) === [
                    [
                        'operation' => 'OPERATION_TOUCH',
                        'relationship' => [
                            'resource' => ['object_type' => 'course', 'object_id' => 'local__course-1'],
                            'relation' => 'instructor',
                            'subject' => ['object' => ['object_type' => 'user', 'object_id' => 'local__user-1']],
                        ],
                    ],
                    [
                        'operation' => 'OPERATION_TOUCH',
                        'relationship' => [
                            'resource' => ['object_type' => 'document', 'object_id' => 'doc-1'],
                            'relation' => 'course',
                            'subject' => ['object' => ['object_type' => 'course', 'object_id' => 'local__course-1']],
                        ],
                    ],
                ];
        });
    }

    public function test_permission_sync_is_idempotent_for_repeated_connector_snapshots(): void
    {
        config()->set('authz.enabled', true);
        config()->set('authz.graph.backend', 'spicedb');
        config()->set('authz.graph.spicedb.api_url', 'http://spicedb.test');
        config()->set('authz.graph.spicedb.preshared_key', 'secret-token');
        Http::fake([
            'http://spicedb.test/v1/relationships/write' => Http::response(['written_at' => ['token' => 'zed-token']]),
        ]);

        Dataset::query()->create([
            'dataset_id' => 'grant-sync-dataset',
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
            'name' => 'Grant Sync Dataset',
            'status' => Dataset::STATUS_ACTIVE,
            'visibility' => Dataset::VISIBILITY_DISCOVERABLE,
            'protected' => true,
            'metadata_json' => [],
            'qdrant_collection' => 'grant_sync_dataset',
            'neo4j_namespace' => 'grant_sync_dataset',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Document::query()->create([
            'id' => 'doc-1',
            'dataset_id' => 'grant-sync-dataset',
            'collection' => 'grant_sync_dataset',
            'source_type' => Document::SOURCE_API,
            'source_url' => 'https://example.test/doc-1',
            'storage_path' => '/tmp/doc-1.md',
            'checksum_sha256' => hash('sha256', 'doc-1'),
            'status' => Document::STATUS_COMPLETED,
            'metadata_json' => [],
        ]);

        $membership = new LmsMembership('local', 'user-1', 'course-1', 'member');
        $relation = new LmsDocumentRelation('local', 'course-1', 'doc-1');
        $baselineRecorded = Http::recorded()->count();

        app(PermissionSyncService::class)->sync([$membership], [$relation]);
        app(PermissionSyncService::class)->sync([$membership], [$relation]);

        $this->assertSame(2, AuthorizationPermissionEvent::query()->count());
        $this->assertSame(1, Group::query()->count());
        $this->assertSame(1, DocumentGrant::query()->count());
        $this->assertSame(1, GroupMember::query()->count());
        $this->assertSame($baselineRecorded + 2, Http::recorded()->count());
    }

    public function test_permission_sync_is_a_no_op_when_authorization_is_disabled(): void
    {
        config()->set('authz.enabled', false);
        Http::fake();

        $result = app(PermissionSyncService::class)->sync(
            [new LmsMembership('local', 'user-1', 'course-1', 'teacher')],
            [new LmsDocumentRelation('local', 'course-1', 'doc-1')],
        );

        $this->assertSame([], $result['relationships']);
        $this->assertSame([
            'enabled' => false,
            'ignored' => true,
            'written' => 0,
        ], $result['graph']);
        $this->assertDatabaseCount('authorization_permission_events', 0);
        $this->assertDatabaseCount('groups', 0);
        $this->assertDatabaseCount('document_grants', 0);
        $this->assertDatabaseCount('group_members', 0);
        Http::assertNothingSent();
    }
}
