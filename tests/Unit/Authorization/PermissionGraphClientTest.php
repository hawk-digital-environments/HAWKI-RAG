<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Services\Authorization\Contracts\PermissionGraphClient;
use App\Services\Authorization\PermissionGraph\OpenFgaPermissionGraphClient;
use App\Services\Authorization\PermissionGraph\PermissionGraphRelationshipFactory;
use App\Services\Authorization\PermissionGraph\SpiceDbPermissionGraphClient;
use App\Services\Authorization\Values\PermissionGraphRelationship;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PermissionGraphClientTest extends TestCase
{
    public function test_default_permission_graph_client_is_spicedb(): void
    {
        config()->set('authz.graph.backend', 'spicedb');

        $this->assertInstanceOf(SpiceDbPermissionGraphClient::class, app(PermissionGraphClient::class));
    }

    public function test_openfga_backend_can_still_be_selected_explicitly(): void
    {
        config()->set('authz.graph.backend', 'openfga');

        $this->assertInstanceOf(OpenFgaPermissionGraphClient::class, app(PermissionGraphClient::class));
    }

    public function test_spicedb_write_relationships_posts_unique_touch_updates(): void
    {
        config()->set('authz.graph.spicedb.api_url', 'http://spicedb.test');
        config()->set('authz.graph.spicedb.preshared_key', 'secret-token');
        Http::fake([
            'http://spicedb.test/v1/relationships/write' => Http::response(['written_at' => ['token' => 'zed-token']]),
        ]);

        $response = app(SpiceDbPermissionGraphClient::class)->writeRelationships([
            new PermissionGraphRelationship('course', 'local__course-1', 'member', 'user', 'local__user-1'),
            new PermissionGraphRelationship('course', 'local__course-1', 'member', 'user', 'local__user-1'),
            new PermissionGraphRelationship('document', 'doc-1', 'course', 'course', 'local__course-1'),
        ]);

        $this->assertSame('spicedb', $response['backend']);
        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'http://spicedb.test/v1/relationships/write'
                && $request->hasHeader('Authorization', 'Bearer secret-token')
                && ($payload['updates'] ?? null) === [
                    [
                        'operation' => 'OPERATION_TOUCH',
                        'relationship' => [
                            'resource' => ['object_type' => 'course', 'object_id' => 'local__course-1'],
                            'relation' => 'member',
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

    public function test_spicedb_checkbulk_posts_viewer_checks_and_maps_permissionship_results(): void
    {
        config()->set('authz.graph.spicedb.api_url', 'http://spicedb.test');
        config()->set('authz.graph.spicedb.preshared_key', 'secret-token');
        config()->set('authz.graph.spicedb.consistency', 'fully_consistent');
        Http::fake([
            'http://spicedb.test/v1/permissions/checkbulk' => Http::response([
                'pairs' => [
                    ['item' => ['permissionship' => 'PERMISSIONSHIP_HAS_PERMISSION']],
                    ['item' => ['permissionship' => 'PERMISSIONSHIP_NO_PERMISSION']],
                ],
            ]),
        ]);

        $allowed = app(SpiceDbPermissionGraphClient::class)->batchCheckDocuments('local', 'user 1', [
            'doc-1',
            'doc-2',
            'doc-1',
        ]);

        $this->assertSame([
            'doc-1' => true,
            'doc-2' => false,
        ], $allowed);
        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'http://spicedb.test/v1/permissions/checkbulk'
                && $request->hasHeader('Authorization', 'Bearer secret-token')
                && ($payload['consistency'] ?? null) === ['fully_consistent' => true]
                && ($payload['items'] ?? null) === [
                    [
                        'resource' => ['object_type' => 'document', 'object_id' => 'doc-1'],
                        'permission' => 'viewer',
                        'subject' => ['object' => ['object_type' => 'user', 'object_id' => 'local__user_1']],
                    ],
                    [
                        'resource' => ['object_type' => 'document', 'object_id' => 'doc-2'],
                        'permission' => 'viewer',
                        'subject' => ['object' => ['object_type' => 'user', 'object_id' => 'local__user_1']],
                    ],
                ];
        });
    }

    public function test_empty_write_and_empty_batch_check_do_not_call_permission_graph(): void
    {
        Http::fake();

        $this->assertSame(
            ['ok' => true, 'written' => 0, 'backend' => 'spicedb'],
            app(SpiceDbPermissionGraphClient::class)->writeRelationships([]),
        );
        $this->assertSame([], app(SpiceDbPermissionGraphClient::class)->batchCheckDocuments('local', 'u1', []));

        Http::assertNothingSent();
    }

    public function test_openfga_adapter_serializes_neutral_relationships_when_selected(): void
    {
        config()->set('authz.graph.openfga.api_url', 'http://openfga.test');
        config()->set('authz.graph.openfga.store_id', 'store-1');
        Http::fake([
            'http://openfga.test/stores/store-1/write' => Http::response(['ok' => true]),
        ]);

        app(OpenFgaPermissionGraphClient::class)->writeRelationships([
            new PermissionGraphRelationship('course', 'local__course-1', 'member', 'user', 'local__user-1'),
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'http://openfga.test/stores/store-1/write'
            && ($request->data()['writes']['tuple_keys'] ?? null) === [
                [
                    'user' => 'user:local__user-1',
                    'relation' => 'member',
                    'object' => 'course:local__course-1',
                ],
            ]);
    }

    public function test_relationship_factory_scopes_ids_for_spicedb_compatible_objects(): void
    {
        $factory = app(PermissionGraphRelationshipFactory::class);

        $this->assertSame('local__user_1', $factory->scopedId('local', 'user 1'));
        $this->assertSame('doc-1', $factory->safe('doc-1'));
    }
}
