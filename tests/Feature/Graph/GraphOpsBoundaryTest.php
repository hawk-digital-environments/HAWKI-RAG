<?php

declare(strict_types=1);

namespace Tests\Feature\Graph;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Models\User;
use App\Services\Graph\GraphOpsBackend;
use App\Services\Graph\Neo4jAdmin;
use App\Services\Graph\Neo4jGraphExplorer;
use App\Services\Pipeline\Health\PipelineHealthService;
use App\Services\User\Values\UserRole;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\QueryBffFixture;
use Tests\TestCase;

class GraphOpsBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private QueryBffFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new QueryBffFixture;
        config()->set(['config.graph_ops_backend' => 'python', 'config.dataset_backend' => 'python',
            'config.pipeline_execution_backend' => 'python', 'config.python_query_dataset_source' => 'database',
            'config.python_graph_ops_url' => 'http://python.test/v1/ops',
            'config.query_backend' => 'python', 'config.text_ingestion_backend' => 'python',
            'config.managed_document_backend' => 'python', 'config.crawler_ingestion_backend' => 'python',
            'config.query_auth.all_datasets_by_default' => false,
            'temporal.enabled' => true, 'temporal.callbacks.secret' => 'epoch14-test-secret',
            'temporal.control_url' => 'http://python.test', 'config.python_url_ingestion_url' => 'http://python.test',

            'config.query_bff' => $this->fixture->config->get('config.query_bff')]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->fixture->close();
        parent::tearDown();
    }

    private function user(array $attributes = []): User
    {
        $identity = 'epoch14-'.bin2hex(random_bytes(4));

        $user = User::query()->create(['username' => $identity, 'email' => $identity.'@example.test', 'ip' => '127.0.0.1']);
        $user->forceFill($attributes)->save();

        return $user;
    }

    public function test_missing_selector_defaults_to_python(): void
    {
        config()->set('config', array_diff_key(config('config'), ['graph_ops_backend' => true]));
        $this->assertSame('python', app(GraphOpsBackend::class)->value());
    }

    public function test_invalid_selector_and_missing_python_prerequisites_fail_closed(): void
    {
        $this->actingAs($this->user());
        Http::fake();
        foreach (['config.graph_ops_backend' => 'typo', 'config.query_backend' => 'laravel', 'temporal.enabled' => false, 'config.dataset_backend' => 'laravel',
            'config.pipeline_execution_backend' => 'laravel', 'config.python_query_dataset_source' => 'environment',
            'config.python_graph_ops_url' => 'http://user:secret@python.test/v1/ops'] as $key => $value) {
            $original = config($key);
            config()->set($key, $value);
            $this->getJson('/api/rag/neo4j/graph/overview?dataset_id=one')->assertStatus(503);
            config()->set($key, $original);
        }
        Http::assertNothingSent();
    }

    public function test_explicit_invalid_browser_credential_is_refused(): void
    {
        Http::fake();
        $this->withToken('invalid')->getJson('/api/rag/neo4j/graph/overview?dataset_id=one')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_python_failure_has_no_laravel_graph_fallback(): void
    {
        $this->actingAs($this->user());
        $this->mock(Neo4jGraphExplorer::class)->shouldNotReceive('overview');
        foreach ([500, 502, 503] as $status) {
            Http::fake(['http://python.test/v1/ops/*' => Http::response(['detail' => 'private message'], $status)]);
            $this->getJson('/api/rag/neo4j/graph/overview?dataset_id=one')->assertStatus(503)->assertDontSee('private message');
        }
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->getJson('/api/rag/neo4j/graph/overview?dataset_id=one')->assertStatus(503);
    }

    public function test_python_enforces_missing_query_grant_without_local_lookup(): void
    {
        $this->actingAs($this->user());
        Http::fake(['http://python.test/v1/ops/*' => Http::response(['detail' => 'dataset_not_found'], 404)]);
        $this->getJson('/api/rag/neo4j/graph/search?dataset_id=one&q=shared')->assertNotFound();
    }

    public function test_signed_graph_request_uses_query_scope(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        Http::fake(['http://python.test/v1/ops/*' => Http::response(['ok' => true, 'results' => []])]);
        $this->getJson('/api/rag/neo4j/graph/semantic-search?dataset_id=one&q=shared')->assertOk();
        Http::assertSent(function ($request) use ($user): bool {
            $claims = JWT::decode(substr($request->header('Authorization')[0], 7), new Key($this->fixture->publicPem, 'RS256'));

            return $claims->scope === 'rag:query' && $claims->sub === (string) $user->getAuthIdentifier()
                && $request['dataset_id'] === 'one' && str_contains($request->url(), '/graph/semantic-search');
        });
        Http::assertSentCount(1);
    }

    public function test_clear_is_manager_only_and_confirmation_is_required(): void
    {
        Http::fake();
        $this->postJson('/api/rag/neo4j/clear', [])->assertUnauthorized();
        $user = $this->user();
        $this->actingAs($user)->postJson('/api/rag/neo4j/clear', [])->assertForbidden();
        $user->role = UserRole::Admin;
        $user->save();
        $this->actingAs($user)->postJson('/api/rag/neo4j/clear', ['dataset_id' => 'one'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_manager_clear_forwards_exact_dataset_and_confirmation(): void
    {
        $this->actingAs($this->user(['role' => UserRole::Admin]));
        Http::fake(['http://python.test/v1/ops/*' => Http::response(['ok' => true, 'deleted_nodes' => 0, 'deleted_relationships' => 0])]);
        $this->postJson('/api/rag/neo4j/clear', ['dataset_id' => 'one', 'confirm' => 'CLEAR_DATASET_GRAPH'])->assertOk();
        Http::assertSent(fn ($request): bool => $request->data() === ['dataset_id' => 'one', 'confirm' => 'CLEAR_DATASET_GRAPH']);
    }

    public function test_legacy_reads_receive_authorized_physical_scope(): void
    {
        config()->set(['config.graph_ops_backend' => 'laravel', 'config.dataset_backend' => 'laravel',
            'config.query_auth.all_datasets_by_default' => false]);
        $user = $this->user();
        Dataset::query()->create(['dataset_id' => 'one', 'name' => 'One', 'status' => 'active',
            'qdrant_collection' => 'canonical-vector', 'neo4j_namespace' => 'canonical-graph']);
        DatasetGrant::query()->create(['dataset_id' => 'one', 'principal_type' => 'user',
            'principal_id' => (string) $user->getAuthIdentifier(), 'permission' => 'query']);
        $this->mock(Neo4jGraphExplorer::class)->shouldReceive('overview')->once()
            ->with(80, ['dataset_id' => 'one', 'neo4j_namespace' => 'canonical-graph'])->andReturn(['ok' => true]);
        $this->actingAs($user)->getJson('/api/rag/neo4j/graph/overview?dataset_id=one')->assertOk();
        $this->getJson('/api/rag/neo4j/graph/overview?dataset_id=ungranted')->assertNotFound();
    }

    public function test_stats_and_monitor_use_python_effective_config(): void
    {
        $this->actingAs($this->user(['role' => UserRole::Admin]));
        config()->set(['config.query_backend' => 'python', 'config.python_query_url' => 'http://python.test/v1/query']);
        Http::fake(['http://python.test/v1/ops/config' => Http::response(['ok' => true, 'authority' => 'python', 'models' => ['runtime' => 'python-model']]),
            'http://python.test/v1/ops/stats' => Http::response(['ok' => true, 'qdrant' => ['ok' => true, 'collections' => []], 'neo4j' => ['ok' => true, 'entities' => 0]]),
            'http://python.test/v1/health' => Http::response(['status' => 'ok'])]);
        $this->getJson('/api/rag/stats')->assertOk()->assertJsonPath('neo4j.entities', 0);
        $this->getJson('/api/rag/monitor')->assertOk()->assertJsonPath('config.models.runtime', 'python-model');
    }

    public function test_python_health_does_not_check_bridge(): void
    {
        config()->set(['config.query_backend' => 'python', 'config.python_query_url' => 'http://python.test/v1/query']);
        Http::fake(['http://python.test/v1/health' => Http::response(['status' => 'ok'])]);
        $this->getJson('/api/rag/health')->assertOk()->assertJsonPath('authority', 'python')
            ->assertJsonPath('status', 200)->assertJsonStructure(['ok', 'status', 'latency_ms', 'endpoint', 'data', 'authority']);
        Http::assertSentCount(1);
    }

    public function test_missing_python_collection_readiness_blocks_the_system_gate(): void
    {
        $this->actingAs($this->user(['role' => UserRole::Admin]));
        config()->set(['config.health_gate.enabled' => true, 'config.health_gate.required' => ['graph'],
            'config.python_query_url' => 'http://python.test/v1/query']);
        $health = $this->createMock(PipelineHealthService::class);
        $health->expects(self::once())->method('check')->willReturn([]);
        $this->app->instance(PipelineHealthService::class, $health);
        Http::fake([
            'http://python.test/v1/health' => Http::response(['status' => 'ok']),
            'http://python.test/v1/ops/stats' => Http::response(['detail' => 'ops_unavailable'], 503),
        ]);
        $this->getJson('/api/health/system-gate')->assertOk()
            ->assertJsonPath('status', 'blocked')->assertJsonPath('blocking.0.key', 'graph')
            ->assertJsonPath('blocking.0.meta.stats.status', 503);
        Http::assertSentCount(2);
    }

    public function test_settings_are_non_effective_and_put_never_changes_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'epoch14-settings-');
        file_put_contents($path, '{}');
        config()->set('config.admin_settings_path', $path);
        try {
            $this->getJson('/api/settings/config')->assertOk()->assertJsonPath('effective', false)->assertJsonPath('managedBy', 'deployment');
            $this->putJson('/api/settings/config', [])->assertStatus(409);
            $this->assertSame('{}', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function test_legacy_clear_uses_python_admission_and_scoped_laravel_executor(): void
    {
        config()->set('config.graph_ops_backend', 'laravel');
        $this->actingAs($this->user(['role' => UserRole::Admin]));
        Http::fake(['http://python.test/v1/ops/graph/clear-admission' => Http::response([
            'ok' => true, 'dataset_id' => 'one', 'neo4j_namespace' => 'trusted-scope', 'operation_id' => str_repeat('a', 32)]),
            'http://python.test/v1/ops/graph/clear-receipt' => Http::response(['ok' => true])]);
        $this->mock(Neo4jAdmin::class)->shouldReceive('clearDataset')->once()
            ->with('one', 'trusted-scope')->andReturn(['ok' => true, 'deleted_nodes' => 3, 'deleted_relationships' => 2]);
        $this->postJson('/api/rag/neo4j/clear', ['dataset_id' => 'one', 'confirm' => 'CLEAR_DATASET_GRAPH'])
            ->assertOk()->assertJsonPath('deleted_nodes', 3);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/clear-receipt')
            && $request['success'] === true && $request['deleted_nodes'] === 3);
    }

    public function test_existing_legacy_artifacts_cannot_be_treated_as_drained(): void
    {
        $this->actingAs($this->user(['role' => UserRole::Admin]));
        Dataset::query()->create(['dataset_id' => 'one', 'name' => 'Legacy', 'status' => 'active', 'qdrant_collection' => 'legacy-vector', 'neo4j_namespace' => 'legacy-graph']);
        Http::fake();
        $this->postJson('/api/rag/neo4j/clear', ['dataset_id' => 'one', 'confirm' => 'CLEAR_DATASET_GRAPH'])
            ->assertStatus(409)->assertJsonPath('error', 'legacy_graph_writers_unconfirmed');
        Http::assertNothingSent();
    }

    public function test_ops_stats_and_monitor_are_manager_only(): void
    {
        Http::fake();
        foreach (['/api/rag/stats', '/api/rag/monitor'] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        $this->actingAs($this->user());
        foreach (['/api/rag/stats', '/api/rag/monitor'] as $path) {
            $this->getJson($path)->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_python_monitor_outage_does_not_return_legacy_config(): void
    {
        $this->actingAs($this->user(['role' => UserRole::Admin]));
        config()->set(['config.query_backend' => 'python', 'config.python_query_url' => 'http://python.test/v1/query']);
        Http::fake(['http://python.test/*' => Http::response([], 503)]);
        $this->getJson('/api/rag/monitor')->assertStatus(503)->assertExactJson(['ok' => false, 'status' => 503, 'authority' => 'python']);
    }

    public function test_snapshots_bind_new_scenes_and_require_explicit_legacy_handling(): void
    {
        $directory = sys_get_temp_dir().'/epoch14-snapshots-'.bin2hex(random_bytes(6));
        config()->set('config.graph_snapshot_path', $directory);
        $this->actingAs($this->user());
        Http::fake(fn ($request) => Http::response(['dataset_id' => $request['dataset_id'], 'neo4j_namespace' => 'trusted']));
        try {
            $response = $this->postJson('/api/rag/neo4j/graph/snapshots', ['dataset_id' => 'one', 'scene' => ['nodes' => [], 'edges' => []]])
                ->assertOk()->assertJsonPath('snapshot.dataset_id', 'one');
            $id = $response->json('snapshot.id');
            $this->getJson('/api/rag/neo4j/graph/snapshots/'.$id.'?dataset_id=two')->assertOk()->assertJsonPath('snapshot.dataset_id', 'one');
            $this->getJson('/api/rag/neo4j/graph/snapshots/'.$id.'?dataset_id=one')->assertOk();
            file_put_contents($directory.'/legacy.json', json_encode(['id' => 'legacy', 'scene' => ['nodes' => [], 'edges' => []]]));
            $this->getJson('/api/rag/neo4j/graph/snapshots/legacy?dataset_id=one')->assertNotFound();
            $this->getJson('/api/rag/neo4j/graph/snapshots/legacy?dataset_id=one&allow_legacy=true')
                ->assertNotFound();
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_malformed_success_and_foreign_scope_are_unavailable(): void
    {
        $this->actingAs($this->user());
        Http::fake(['http://python.test/*' => Http::response([])]);
        $this->getJson('/api/rag/neo4j/graph/overview?dataset_id=one')->assertStatus(503);
        config()->set('config.graph_ops_backend', 'laravel');
        Http::fake(['http://python.test/*' => Http::response(['dataset_id' => 'foreign', 'neo4j_namespace' => 'foreign-scope'])]);
        $this->getJson('/api/rag/neo4j/graph/overview?dataset_id=one')->assertStatus(503);
    }
}
