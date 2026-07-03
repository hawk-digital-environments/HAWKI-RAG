<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuthorizationPermissionEvent;
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
        config()->set('authz.graph.backend', 'spicedb');
        config()->set('authz.graph.spicedb.api_url', 'http://spicedb.test');
        config()->set('authz.graph.spicedb.preshared_key', 'secret-token');
        Http::fake([
            'http://spicedb.test/v1/relationships/write' => Http::response(['written_at' => ['token' => 'zed-token']]),
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
        config()->set('authz.graph.backend', 'spicedb');
        config()->set('authz.graph.spicedb.api_url', 'http://spicedb.test');
        config()->set('authz.graph.spicedb.preshared_key', 'secret-token');
        Http::fake([
            'http://spicedb.test/v1/relationships/write' => Http::response(['written_at' => ['token' => 'zed-token']]),
        ]);

        $membership = new LmsMembership('local', 'user-1', 'course-1', 'member');
        $relation = new LmsDocumentRelation('local', 'course-1', 'doc-1');

        app(PermissionSyncService::class)->sync([$membership], [$relation]);
        app(PermissionSyncService::class)->sync([$membership], [$relation]);

        $this->assertSame(2, AuthorizationPermissionEvent::query()->count());
        Http::assertSentCount(2);
    }
}
