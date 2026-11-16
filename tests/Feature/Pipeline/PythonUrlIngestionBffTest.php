<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Models\Dataset;
use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Models\User;
use App\Services\Pipeline\Clients\PythonUrlIngestionClient;
use App\Services\Pipeline\CrawlerCallbackBoundary;
use App\Services\Pipeline\Health\PipelineHealthService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\QueryBffFixture;
use Tests\TestCase;

final class PythonUrlIngestionBffTest extends TestCase
{
    use RefreshDatabase;

    private QueryBffFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new QueryBffFixture;
        config()->set([
            'config.crawler_ingestion_backend' => 'python',
            'config.pipeline_execution_backend' => 'python',
            'temporal.callbacks.secret' => 'url-test-secret',
            'temporal.control_url' => 'http://python.test',
            'temporal.enabled' => true,
            'config.python_url_ingestion_url' => 'http://python.test',
            'config.query_bff' => $this->fixture->config->get('config.query_bff'),
        ]);
        Http::preventStrayRequests();
        Http::fake(['http://python.test/internal/url-ingestion/readiness' => Http::response(['ready' => true])]);
    }

    protected function tearDown(): void
    {
        $this->fixture->close();
        parent::tearDown();
    }

    private function user(string $name = 'one'): User
    {
        return User::query()->create(['username' => $name, 'email' => $name.'@test.example', 'ip' => $name === 'one' ? '127.0.0.91' : '127.0.0.92']);
    }

    private function accepted(): array
    {
        return ['success' => true, 'task_id' => 'task_python', 'task' => ['task_id' => 'task_python', 'metadata' => ['origin' => 'test']]];
    }

    private function noLegacyWrites(): void
    {
        foreach (['pipeline_tasks', 'pipeline_jobs', 'ingestion_sources'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/workflow-control/'));
    }

    public function test_single_user_aliases_and_metadata_forward_with_exact_ingest_jwt_and_201(): void
    {
        $user = $this->user();
        $body = ['datasetId' => 'only-python', 'urls' => 'https://example.org/a,https://example.org/b', 'metadata' => ['origin' => ['cli', 'mcp']], 'refreshCadence' => 'weekly'];
        Http::fake(['http://python.test/v1/pipeline/tasks/start' => Http::response($this->accepted(), 201)]);
        $this->withHeader('Idempotency-Key', 'key-one')->postJson('/api/pipeline/tasks/start', $body)->assertCreated()->assertJson($this->accepted());
        Http::assertSent(function ($request) use ($user, $body): bool {
            if ($request->url() !== 'http://python.test/v1/pipeline/tasks/start') {
                return false;
            }
            $claims = JWT::decode(substr($request->header('Authorization')[0], 7), new Key($this->fixture->publicPem, 'RS256'));

            return $request->data() === $body && $request->header('Idempotency-Key') === ['key-one']
                && $claims->sub === (string) $user->id && $claims->tenant_id === 'default'
                && $claims->azp === 'hawki-bff' && $claims->scope === 'rag:ingest' && $claims->exp - $claims->iat <= 60;
        });
        $this->noLegacyWrites();
    }

    public function test_anonymous_and_multiple_users_without_credentials_are_denied(): void
    {
        $this->postJson('/api/pipeline/tasks/start', ['dataset_id' => 'web'])->assertUnauthorized();
        $this->user();
        $this->user('two');
        $this->postJson('/api/pipeline/tasks/start', ['dataset_id' => 'web'])->assertUnauthorized();
        Http::assertNothingSent();
        $this->noLegacyWrites();
    }

    public function test_invalid_explicit_authorization_cannot_use_an_authenticated_session(): void
    {
        $this->actingAs($this->user());
        $this->withHeader('Authorization', 'Bearer invalid')->postJson('/api/pipeline/tasks/start', [])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_session_and_compatible_pat_resolve_a_concrete_user(): void
    {
        $user = $this->user();
        $this->user('two');
        Http::fake(['http://python.test/v1/pipeline/tasks/start' => Http::response($this->accepted(), 201)]);
        $this->actingAs($user)->postJson('/api/pipeline/tasks/start', ['source_url' => 'https://example.org'])->assertCreated();
        $token = $user->createToken('compatible', ['existing:ability'])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/pipeline/tasks/start', ['urls' => ['https://example.org']])->assertCreated();
        $this->noLegacyWrites();
    }

    public function test_sitemap_is_never_fetched_in_laravel_and_python_denial_never_falls_back(): void
    {
        $this->user();
        Http::fake(['http://python.test/v1/pipeline/tasks/start' => Http::response(['success' => false], 503)]);
        $this->postJson('/api/pipeline/tasks/start', ['dataset_id' => 'web', 'sitemap_url' => 'http://169.254.169.254/metadata'])->assertStatus(503);
        Http::assertSentCount(2);
        $this->noLegacyWrites();
    }

    public function test_invalid_selector_fails_closed(): void
    {
        config()->set('config.crawler_ingestion_backend', 'typo');
        $this->postJson('/api/pipeline/tasks/start', [])->assertStatus(503);
        Http::assertNothingSent();
        $this->noLegacyWrites();
    }

    public function test_recovery_and_source_deletion_proxy_without_laravel_redispatch(): void
    {
        $this->user();
        Http::fake([
            'http://python.test/internal/url-ingestion/ownership' => Http::response(['owner' => 'url']),
            'http://python.test/v1/pipeline/*' => Http::response(['success' => true, 'recovery' => ['owner' => 'url']], 200),
        ]);
        $this->postJson('/api/pipeline/recovery/jobs/job-python/retry')->assertOk();
        $this->deleteJson('/api/pipeline/sources/source_python')->assertOk();
        Http::assertSent(fn ($request): bool => $request->url() === 'http://python.test/v1/pipeline/recovery/jobs/job-python/retry');
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && $request->url() === 'http://python.test/v1/pipeline/sources/source_python');
        $this->noLegacyWrites();
    }

    public function test_raw_crawler_mutations_cannot_bypass_python_ownership(): void
    {
        $this->user();
        foreach (['/api/scraper/tasks/start', '/api/scraper/jobs/raw/resume', '/api/scraper/jobs/raw/pause', '/api/scraper/jobs/raw/cancel'] as $path) {
            $this->postJson($path, [])->assertStatus(409);
        }
        Http::assertNothingSent();
        $this->noLegacyWrites();
    }

    public function test_pending_writer_drain_preserves_accepted_status_without_fallback(): void
    {
        $this->user();
        Http::fake([
            'http://python.test/v1/pipeline/sources/source_python' => Http::response(['success' => false, 'message' => 'Waiting for writers.'], 202),
        ]);
        $this->withHeader('Idempotency-Key', 'pending-delete')
            ->deleteJson('/api/pipeline/sources/source_python')
            ->assertStatus(202)->assertJsonPath('success', false);
        Http::assertSent(fn ($request): bool => $request->header('Idempotency-Key') === ['pending-delete']);
        Http::assertSentCount(1);
        $this->noLegacyWrites();
    }

    public function test_signed_url_writer_routes_only_to_proven_url_owner(): void
    {
        config()->set('temporal.callbacks.secret', 'callback-test-secret');
        Http::fake([
            'http://python.test/internal/url-ingestion/ownership' => Http::response(['owner' => 'url']),
            'http://python.test/internal/url-ingestion/writers' => Http::response(['success' => true, 'status' => 'active', 'targets' => []]),
        ]);
        $event = ['schema_version' => 1, 'event_id' => 'url-writer', 'action' => 'acquire', 'owner' => 'url', 'url_ingestion_operation_id' => str_repeat('a', 64), 'generation' => 1, 'dataset_id' => 'web', 'workflow_id' => 'ingest-text-prefix-is-not-owner', 'run_id' => 'run-one', 'activity_id' => 'index_markdown', 'attempt' => 1, 'source_id' => 'source-one', 'job_id' => 'job-one', 'targets' => []];
        $raw = json_encode($event, JSON_THROW_ON_ERROR);
        $stamp = (string) time();
        $this->call('POST', '/api/internal/pipeline/managed-writers', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HAWKI_TIMESTAMP' => $stamp, 'HTTP_X_HAWKI_SIGNATURE' => 'v1='.hash_hmac('sha256', $stamp.'.'.$raw, 'callback-test-secret'),
        ], $raw)->assertOk();
        Http::assertSentCount(2);
        $this->noLegacyWrites();
    }

    public function test_unknown_callback_ownership_fails_closed(): void
    {
        config()->set('temporal.callbacks.secret', 'callback-test-secret');
        Http::fake(['http://python.test/internal/url-ingestion/ownership' => Http::response(['success' => false], 409)]);
        $boundary = app(CrawlerCallbackBoundary::class);
        $result = $boundary->relay('unproven', '{}', 'events');
        $this->assertSame(409, $result['status']);
        Http::assertSentCount(1);
        $this->noLegacyWrites();
    }

    public function test_python_managed_recovery_routes_to_its_owner_without_legacy_dispatch(): void
    {
        $this->user();
        Http::fake(['http://python.test/internal/url-ingestion/ownership' => Http::response(['owner' => 'managed']),
            'http://python.test/v1/pipeline/recovery/jobs/non-url/retry' => Http::response(['success' => true, 'recovery' => ['attempted' => 1, 'retried' => 1, 'skipped' => 0, 'failed' => 0, 'jobs' => []]])]);
        $this->postJson('/api/pipeline/recovery/jobs/non-url/retry')->assertOk()->assertJsonPath('recovery.retried', 1);
        Http::assertSentCount(2);
        $this->noLegacyWrites();
    }

    private function legacy(string $id, bool $managed = false): void
    {
        Dataset::query()->firstOrCreate(['dataset_id' => 'web'], ['name' => 'web', 'status' => 'active', 'qdrant_collection' => 'web', 'neo4j_namespace' => 'web']);
        PipelineTask::query()->create(['task_id' => 'task-'.$id, 'dataset_id' => 'web', 'status' => 'failed', 'started_at' => '2026-01-01T00:00:00Z', 'metadata' => []]);
        PipelineJob::query()->create(['job_id' => $id, 'task_id' => 'task-'.$id, 'source_id' => 'source-'.$id, 'source_url' => 'https://example.org', 'job_type' => 'ingest', 'status' => 'failed', 'metadata' => []]);
        IngestionSource::query()->create(['source_id' => 'source-'.$id, 'task_id' => 'task-'.$id, 'dataset_id' => 'web', 'source_url' => 'https://example.org', 'index_status' => 'failed', 'raw_storage_path' => '/shared/sources/source-'.$id.'/raw/', 'markdown_storage_path' => '/shared/sources/source-'.$id.'/markdown/', 'metadata' => $managed ? ['request' => ['metadata' => ['managed_document_id' => 'adoc_'.str_repeat('a', 32)]], 'managed_writer_protocol' => 1, 'managed_writers' => []] : []]);
    }

    private function mixedTransport(bool $urlFailure = false): void
    {
        config()->set(['temporal.enabled' => true, 'temporal.control_url' => 'http://control.test', 'temporal.callbacks.secret' => 'callback-secret']);
        Http::fake(function ($request) use ($urlFailure) {
            $data = $request->data();
            if (str_ends_with($request->url(), '/ownership')) {
                $identity = $data['job_id'] ?? $data['task_id'] ?? $data['workflow_id'] ?? '';

                return Http::response(['owner' => match ($identity) {
                    'python-url', 'task-python-url', 'python-workflow' => 'url',
                    'python-managed' => 'managed', default => 'absent',
                }]);
            }
            if (str_ends_with($request->url(), '/legacy-access')) {
                return Http::response(['success' => true, 'identities' => array_column($data['candidates'], 'identity')]);
            }
            if (str_ends_with($request->url(), '/recovery/jobs/python-url/retry')) {
                return $urlFailure ? Http::response(['success' => false], 503) : Http::response(['success' => true, 'recovery' => ['attempted' => 1, 'retried' => 1, 'skipped' => 0, 'failed' => 0, 'jobs' => [['job_id' => 'python-url', 'result' => 'retried']]]]);
            }
            if (str_ends_with($request->url(), '/recovery/jobs/python-managed/retry')) {
                return Http::response(['success' => true, 'recovery' => ['attempted' => 1, 'retried' => 1, 'skipped' => 0, 'failed' => 0, 'jobs' => [['job_id' => 'python-managed', 'result' => 'retried']]]]);
            }
            if (str_ends_with($request->url(), '/source/retry-identity')) {
                return Http::response(['workflow_id' => 'ingest-source-'.$data['source_id'].'-retry-'.$data['attempt'].'-'.$data['job_id']]);
            }
            if (str_ends_with($request->url(), '/source/start')) {
                return Http::response(['workflow_id' => $data['workflow_id'], 'run_id' => 'legacy-run']);
            }
            if (str_ends_with($request->url(), '/execution/status')) {
                return Http::response(['status' => 'RUNNING']);
            }
            if (str_ends_with($request->url(), '/recovery/candidates')) {
                return Http::response(['success' => true, 'jobs' => [['job_id' => 'python-url']]]);
            }

            return Http::response(['success' => false], 503);
        });
    }

    public function test_mixed_bulk_partitions_durable_owners_deduplicates_and_reports_unknown(): void
    {
        $this->user();
        $this->legacy('legacy-url');
        $this->legacy('legacy-managed', true);
        $this->mixedTransport();
        $response = $this->postJson('/api/pipeline/recovery/jobs/retry-selected', ['job_ids' => ['legacy-url', 'python-url', 'legacy-managed', 'python-managed', 'unknown', 'python-url']]);
        $response->assertOk()->assertJsonPath('recovery.retried', 4)->assertJsonPath('recovery.failed', 1);
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/recovery/jobs/python-url/retry')));
        $this->assertCount(2, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/source/start')));
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/source/start') && in_array($request->data()['workflow_input']['job_id'] ?? null, ['python-url', 'python-managed', 'unknown'], true));
        $this->assertDatabaseCount('pipeline_jobs', 2);
    }

    public function test_one_remote_owner_failure_does_not_redirect_or_block_legacy_partition(): void
    {
        $this->user();
        $this->legacy('legacy-url');
        $this->mixedTransport(true);
        $this->postJson('/api/pipeline/recovery/jobs/retry-selected', ['jobIds' => ['python-url', 'legacy-url']])->assertOk()->assertJsonPath('recovery.failed', 1)->assertJsonPath('recovery.retried', 1);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/source/start') && ($request->data()['workflow_input']['job_id'] ?? null) === 'python-url');
    }

    public function test_conflicting_owner_and_unknown_controls_cannot_mutate_local_rows(): void
    {
        $this->user();
        $this->legacy('python-url');
        $this->mixedTransport();
        $this->postJson('/api/pipeline/recovery/jobs/python-url/retry')->assertStatus(409);
        $this->postJson('/api/pipeline/tasks/task-python-url/cancel')->assertStatus(409);
        $this->deleteJson('/api/pipeline/tasks/task-python-url')->assertStatus(409);
        $this->postJson('/api/pipeline/tasks/missing/cancel')->assertStatus(409);
        $this->assertDatabaseHas('pipeline_jobs', ['job_id' => 'python-url', 'status' => 'failed']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/workflow-control/'));
    }

    public function test_python_status_events_and_log_download_share_the_python_read_boundary(): void
    {
        $this->user();
        Http::fake([
            'http://python.test/internal/url-ingestion/ownership' => Http::response(['owner' => 'url']),
            'http://python.test/v1/pipeline/tasks/task-python/events' => Http::response(['success' => true, 'events' => [['event_id' => 'event-one']]]),
            'http://python.test/v1/pipeline/tasks/task-python/stages/scraper/logs' => Http::response(['success' => true, 'log' => ['text' => 'worker event']]),
            'http://python.test/v1/pipeline/status/python-url' => Http::response(['success' => true, 'status' => 'running']),
        ]);
        $this->getJson('/api/pipeline/status/python-url')->assertOk()->assertJsonPath('status', 'running');
        $this->getJson('/api/pipeline/tasks/task-python/events')->assertOk()->assertJsonPath('events.0.event_id', 'event-one');
        $this->get('/api/pipeline/tasks/task-python/stages/scraper/logs/download')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertContent('worker event');
        $this->noLegacyWrites();
    }

    public function test_task_inventory_applies_one_global_limit_and_does_not_create_local_python_rows(): void
    {
        $this->user();
        $this->legacy('legacy-url');
        Http::fake([
            'http://python.test/internal/url-ingestion/ownership' => Http::response(['owner' => 'absent']),
            'http://python.test/v1/pipeline/tasks*' => Http::response(['success' => true, 'tasks' => [['task_id' => 'python-task', 'started_at' => '2026-11-06T12:00:00Z']]]),
            'http://python.test/v1/pipeline/legacy-access' => Http::response(['success' => true, 'identities' => ['task-legacy-url']]),
        ]);
        $response = $this->getJson('/api/pipeline/tasks?limit=1')->assertOk()->assertJsonCount(1, 'tasks');
        $response->assertJsonPath('tasks.0.task_id', 'python-task');
        $this->assertDatabaseCount('pipeline_tasks', 1);
        $this->assertDatabaseMissing('pipeline_tasks', ['task_id' => 'python-task']);
    }

    public function test_pipeline_selector_defaults_to_laravel_and_invalid_values_fail_closed(): void
    {
        $defaults = require base_path('config/config.php');
        $this->assertSame('laravel', $defaults['pipeline_execution_backend']);
        config()->set('config.pipeline_execution_backend', 'invalid');
        $this->postJson('/api/pipeline/tasks/start', [])->assertStatus(503);
        Http::assertNothingSent();
        $this->noLegacyWrites();
    }

    public function test_python_pipeline_requires_crawler_and_workflow_configuration(): void
    {
        $this->user();
        foreach (['config.crawler_ingestion_backend', 'temporal.control_url', 'temporal.callbacks.secret'] as $key) {
            $old = config($key);
            config()->set($key, '');
            $this->postJson('/api/pipeline/tasks/start', ['source_url' => 'https://example.org'])->assertStatus(503);
            config()->set($key, $old);
        }
        Http::assertNothingSent();
        $this->noLegacyWrites();
    }

    public function test_python_task_cancel_and_delete_forward_without_legacy_rows(): void
    {
        $this->user();
        Http::fake([
            'http://python.test/internal/url-ingestion/ownership' => Http::response(['owner' => 'url']),
            'http://python.test/v1/pipeline/tasks/task-python/cancel' => Http::response(['success' => true, 'task_id' => 'task-python', 'task' => ['status' => 'cancelled']]),
            'http://python.test/v1/pipeline/tasks/task-python' => Http::response(['success' => true, 'task_id' => 'task-python', 'deleted' => true]),
        ]);
        $this->withHeader('Idempotency-Key', 'cancel-one')->postJson('/api/pipeline/tasks/task-python/cancel')->assertOk()->assertJsonPath('task.status', 'cancelled');
        $this->deleteJson('/api/pipeline/tasks/task-python')->assertOk()->assertJsonPath('deleted', true);
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/tasks/task-python'));
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/cancel') && $request->header('Idempotency-Key') === ['cancel-one']);
        $this->noLegacyWrites();
        $this->assertDatabaseCount('pipeline_stage_states', 0);
        $this->assertDatabaseCount('pipeline_worker_events', 0);
    }

    public function test_rollback_pipeline_selector_does_not_reclassify_python_task_commands_or_reads(): void
    {
        $this->user();
        config()->set('config.pipeline_execution_backend', 'laravel');
        Http::fake([
            'http://python.test/internal/url-ingestion/ownership' => Http::response(['owner' => 'url']),
            'http://python.test/v1/pipeline/tasks/task-python*' => Http::response(['success' => true, 'task_id' => 'task-python', 'task' => ['status' => 'cancelled']]),
        ]);
        $this->postJson('/api/pipeline/tasks/task-python/cancel')->assertOk();
        $this->getJson('/api/pipeline/tasks/task-python')->assertOk();
        $this->deleteJson('/api/pipeline/tasks/task-python')->assertOk();
        $this->noLegacyWrites();
    }

    public function test_python_command_outage_never_falls_back_to_local_mutation(): void
    {
        $this->user();
        Http::fake([
            'http://python.test/internal/url-ingestion/ownership' => Http::response(['owner' => 'url']),
            'http://python.test/v1/pipeline/tasks/task-python*' => Http::failedConnection(),
        ]);
        $this->postJson('/api/pipeline/tasks/task-python/cancel')->assertStatus(502);
        $this->deleteJson('/api/pipeline/tasks/task-python')->assertStatus(502);
        $this->noLegacyWrites();
    }

    public function test_durable_legacy_task_cancel_and_delete_stay_local_during_drain(): void
    {
        $this->user();
        $this->legacy('legacy-url');
        $this->mixedTransport();
        $this->postJson('/api/pipeline/tasks/task-legacy-url/cancel')->assertOk();
        $this->deleteJson('/api/pipeline/tasks/task-legacy-url')->assertOk()->assertJsonPath('deleted', true);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v1/pipeline/tasks/'));
        $this->assertDatabaseMissing('pipeline_tasks', ['task_id' => 'task-legacy-url']);
    }

    public function test_task_retry_uses_existing_python_recovery_endpoint(): void
    {
        $this->user();
        Http::fake([
            'http://python.test/internal/url-ingestion/ownership' => Http::response(['owner' => 'url']),
            'http://python.test/v1/pipeline/recovery/tasks/task-python/retry-failed' => Http::response(['success' => true, 'recovery' => ['scope' => 'task']]),
        ]);
        $this->postJson('/api/pipeline/recovery/tasks/task-python/retry-failed')->assertOk()->assertJsonPath('recovery.scope', 'task');
        $this->postJson('/api/pipeline/tasks/task-python/retry')->assertStatus(409);
        $this->noLegacyWrites();
    }

    public function test_inventory_python_rows_precede_newer_legacy_rows_and_conflicts_fail_closed(): void
    {
        $this->user();
        $this->legacy('legacy-url');
        Http::fake(['http://python.test/v1/pipeline/tasks*' => Http::response(['success' => true, 'tasks' => [
            ['task_id' => 'python-task', 'started_at' => '2020-01-01T00:00:00Z'],
        ]])]);
        $this->mixedTransport();
        $this->getJson('/api/pipeline/tasks?limit=1')->assertOk()->assertJsonPath('tasks.0.task_id', 'python-task');
    }

    public function test_inventory_ownership_conflict_is_not_hidden_by_deduplication(): void
    {
        $this->user();
        $this->legacy('python-url');
        Http::fake(['http://python.test/v1/pipeline/tasks*' => Http::response(['success' => true, 'tasks' => [
            ['task_id' => 'task-python-url', 'started_at' => '2026-11-06T12:00:00Z'],
        ]])]);
        $this->mixedTransport();
        $this->getJson('/api/pipeline/tasks')->assertStatus(409);
    }

    public function test_scraper_catalog_remains_readable_while_mutations_are_blocked(): void
    {
        config()->set('scraper.task_ui_url', 'http://catalog.test');
        Http::fake([
            'http://catalog.test/ui/api/profiles' => Http::response(['profiles' => []]),
            'http://catalog.test/ui/api/tasks' => Http::response(['tasks' => []]),
        ]);
        $this->getJson('/api/scraper/tasks')->assertOk()->assertJsonStructure(['success', 'tasks']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/tasks') && ! str_contains($request->url(), '/ui/api/'));
        $this->user();
        $this->postJson('/api/scraper/tasks/start', ['taskId' => 'one'])->assertStatus(409);
    }

    public function test_pipeline_health_reports_python_readiness_and_drain_counts(): void
    {
        $this->legacy('legacy-url');
        PipelineTask::query()->update(['status' => 'running']);
        PipelineJob::query()->update(['status' => 'running']);
        $this->app->instance(PipelineHealthService::class, new readonly class extends PipelineHealthService
        {
            public function __construct() {}

            public function check(int $timeout): array
            {
                return [];
            }
        });
        $this->getJson('/api/pipeline/health')->assertOk()
            ->assertJsonPath('pipeline_execution_backend', 'python')
            ->assertJsonPath('python_execution.ready', true)
            ->assertJsonPath('active_legacy_task_count', 1)
            ->assertJsonPath('active_legacy_job_count', 1)
            ->assertJsonPath('legacy_drain_complete', false);
        config()->set('temporal.control_url', 'invalid');
        $this->getJson('/api/pipeline/health')->assertStatus(503)->assertJsonPath('success', false);
    }

    public function test_rollback_start_replay_keeps_durable_python_task_authority(): void
    {
        $this->user();
        config()->set('config.pipeline_execution_backend', 'laravel');
        Http::fake([
            'http://python.test/internal/url-ingestion/ownership' => Http::response(['owner' => 'url']),
            'http://python.test/v1/pipeline/tasks/start' => Http::response($this->accepted(), 201),
            'http://python.test/v1/pipeline/start-request*' => Http::response(['success' => true, 'owner' => 'url', 'task_id' => 'task_python']),
        ]);
        $this->withHeader('Idempotency-Key', 'lost-response')->postJson('/api/pipeline/tasks/start', ['task_id' => 'task_python', 'source_url' => 'https://example.org'])->assertCreated();
        $this->noLegacyWrites();
    }

    public function test_legacy_worker_event_remains_local_during_python_execution_drain(): void
    {
        $this->legacy('legacy-url');
        PipelineJob::query()->update(['temporal_workflow_id' => 'legacy-workflow', 'temporal_run_id' => 'run-one']);
        IngestionSource::query()->update(['temporal_workflow_id' => 'legacy-workflow']);
        $this->mixedTransport();
        $event = ['schema_version' => 1, 'event_type' => 'pipeline.stage.status', 'event_id' => 'legacy-event', 'producer' => 'scraper', 'timestamp' => gmdate('c'),
            'workflow_id' => 'legacy-workflow', 'run_id' => 'run-one', 'activity_id' => 'scrape_source', 'attempt' => 1,
            'job_id' => 'legacy-url', 'task_id' => 'task-legacy-url', 'source_id' => 'source-legacy-url', 'stage' => 'scrape', 'phase' => 'scrape_source', 'status' => 'running'];
        $raw = json_encode($event, JSON_THROW_ON_ERROR);
        $stamp = (string) time();
        $this->call('POST', '/api/internal/pipeline/worker-events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HAWKI_TIMESTAMP' => $stamp, 'HTTP_X_HAWKI_SIGNATURE' => 'v1='.hash_hmac('sha256', $stamp.'.'.$raw, 'callback-secret'),
        ], $raw)->assertAccepted();
        $this->assertDatabaseHas('pipeline_worker_events', ['event_id' => 'legacy-event']);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/url-ingestion/events'));
    }

    public function test_python_worker_event_is_relayed_after_hmac_without_any_legacy_write(): void
    {
        config()->set('temporal.callbacks.secret', 'callback-secret');
        Http::fake([
            'http://python.test/internal/url-ingestion/ownership' => Http::response(['owner' => 'url']),
            'http://python.test/internal/url-ingestion/events' => Http::response(['event_id' => 'python-event', 'accepted' => true, 'duplicate' => false], 202),
        ]);
        $event = ['schema_version' => 1, 'event_type' => 'pipeline.stage.status', 'event_id' => 'python-event', 'producer' => 'scraper', 'timestamp' => gmdate('c'),
            'workflow_id' => 'python-workflow', 'run_id' => 'run-one', 'activity_id' => 'scrape_source', 'attempt' => 1,
            'job_id' => 'python-job', 'task_id' => 'task-python', 'source_id' => 'source-python', 'stage' => 'scrape', 'phase' => 'scrape_source', 'status' => 'running'];
        $raw = json_encode($event, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $stamp = (string) time();
        $this->call('POST', '/api/internal/pipeline/worker-events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HAWKI_TIMESTAMP' => $stamp, 'HTTP_X_HAWKI_SIGNATURE' => 'v1='.hash_hmac('sha256', $stamp.'.'.$raw, 'callback-secret'),
        ], $raw)->assertAccepted();
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/url-ingestion/events') && $request->body() === $raw);
        $this->noLegacyWrites();
        $this->assertDatabaseCount('pipeline_worker_events', 0);
        $this->assertDatabaseCount('pipeline_stage_states', 0);
    }

    public function test_python_readiness_failure_rejects_start_before_any_legacy_execution(): void
    {
        $this->user();
        $this->app->instance(PythonUrlIngestionClient::class, new readonly class extends PythonUrlIngestionClient
        {
            public function __construct() {}

            public function readiness(): array
            {
                return ['ready' => false];
            }
        });
        $this->postJson('/api/pipeline/tasks/start', ['source_url' => 'https://example.org'])->assertStatus(503);
        Http::assertNothingSent();
        $this->noLegacyWrites();
    }

    public function test_cli_rollback_cannot_create_legacy_rows_for_an_existing_python_task(): void
    {
        $this->user();
        config()->set('config.pipeline_execution_backend', 'laravel');
        Http::fake([
            'http://python.test/internal/url-ingestion/ownership' => Http::response(['owner' => 'url']),
            'http://python.test/v1/pipeline/tasks/start' => Http::response($this->accepted(), 201),
            'http://python.test/v1/pipeline/start-request*' => Http::response(['success' => true, 'owner' => 'url', 'task_id' => 'task_python']),
        ]);
        $this->artisan('pipeline:start-task', ['--task-id' => 'task_python', '--source-url' => 'https://example.org', '--idempotency-key' => 'cli-key'])->assertSuccessful();
        $this->noLegacyWrites();
    }

    public function test_legacy_job_updates_require_existing_same_task_owner(): void
    {
        $this->user();
        $this->legacy('legacy-one');
        $this->legacy('legacy-two');
        $this->mixedTransport();
        foreach (['new-job', 'python-url', 'legacy-two'] as $id) {
            $this->postJson('/api/pipeline/tasks/task-legacy-one/jobs', ['job_id' => $id, 'status' => 'running'])->assertStatus(409);
        }
        $this->assertDatabaseCount('pipeline_jobs', 2);
        $this->assertDatabaseHas('pipeline_jobs', ['job_id' => 'legacy-two', 'task_id' => 'task-legacy-two']);
        $this->postJson('/api/pipeline/tasks/task-legacy-one/jobs', ['job_id' => 'legacy-one', 'metadata' => ['owner' => 'url']])->assertStatus(409);
        $this->postJson('/api/pipeline/tasks/task-legacy-one/jobs', ['job_id' => 'legacy-one', 'metadata' => ['url_ingestion_operation_id' => 'injected']])->assertStatus(409);
        $this->postJson('/api/pipeline/tasks/task-legacy-one/jobs', ['job_id' => 'legacy-one', 'status' => 'running'])->assertOk();
        $this->assertDatabaseHas('pipeline_jobs', ['job_id' => 'legacy-one', 'task_id' => 'task-legacy-one', 'status' => 'running']);
        $this->assertDatabaseCount('pipeline_jobs', 2);
        config()->set(['config.pipeline_execution_backend' => 'laravel', 'config.crawler_ingestion_backend' => 'laravel']);
        $this->postJson('/api/pipeline/tasks/task-legacy-one/jobs', ['job_id' => 'legacy-two', 'status' => 'running'])->assertStatus(409);
        $this->assertDatabaseHas('pipeline_jobs', ['job_id' => 'legacy-two', 'task_id' => 'task-legacy-two']);
    }

    public function test_job_update_rejects_conflict_outage_and_dataset_mismatch(): void
    {
        $this->user();
        $this->legacy('legacy-one');
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/ownership')) {
                return isset($request->data()['job_id']) ? Http::response([], 503) : Http::response(['owner' => 'absent']);
            }

            return Http::response(['success' => true, 'identities' => ['task-legacy-one']]);
        });
        $this->postJson('/api/pipeline/tasks/task-legacy-one/jobs', ['job_id' => 'legacy-one', 'status' => 'running'])->assertStatus(409);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/ownership')) {
                return Http::response(['owner' => isset($request->data()['job_id']) ? 'url' : 'absent']);
            }

            return Http::response(['success' => true, 'identities' => ['task-legacy-one']]);
        });
        $this->postJson('/api/pipeline/tasks/task-legacy-one/jobs', ['job_id' => 'legacy-one', 'status' => 'running'])->assertStatus(409);
        IngestionSource::query()->update(['dataset_id' => null]);
        $this->postJson('/api/pipeline/tasks/task-legacy-one/jobs', ['job_id' => 'legacy-one'])->assertStatus(409);
        $this->assertDatabaseCount('pipeline_jobs', 1);
        $this->assertDatabaseHas('pipeline_jobs', ['job_id' => 'legacy-one', 'status' => 'failed']);
    }

    public function test_cli_python_start_requires_a_retained_key(): void
    {
        $this->user();
        $this->artisan('pipeline:start-task', ['--source-url' => 'https://example.org'])->assertFailed();
        $this->noLegacyWrites();
    }

    public function test_legacy_drain_updates_preserve_all_identity_and_metadata_evidence(): void
    {
        $this->user();
        $this->legacy('legacy-one');
        $evidence = ['owner' => 'laravel', 'request' => ['source_id' => 'source-legacy-one'], 'pending_recovery' => ['state' => 'uncertain'], 'workflow_id' => 'legacy-workflow'];
        PipelineJob::query()->update(['metadata' => $evidence, 'temporal_workflow_id' => 'legacy-workflow']);
        $this->mixedTransport();
        foreach (['owner', 'url_ingestion_operation_id', 'task_id', 'job_id', 'source_id', 'dataset_id', 'workflow_id', 'temporal_workflow_id', 'run_id', 'request', 'pending_recovery'] as $field) {
            $this->postJson('/api/pipeline/tasks/task-legacy-one/jobs', ['job_id' => 'legacy-one', 'status' => 'running', 'metadata' => [$field => null]])->assertStatus(409);
            $this->assertSame($evidence, PipelineJob::query()->first()->metadata);
            $this->assertDatabaseHas('pipeline_jobs', ['job_id' => 'legacy-one', 'task_id' => 'task-legacy-one', 'status' => 'failed']);
        }
        $this->postJson('/api/pipeline/tasks/task-legacy-one/jobs', [
            'job_id' => 'legacy-one', 'status' => 'running', 'metadata' => [],
            'task_id' => 'other-task', 'dataset_id' => 'other-dataset', 'source_id' => 'other-source',
            'temporal_workflow_id' => 'python-workflow', 'workflow_id' => 'python-workflow',
        ])->assertOk();
        $job = PipelineJob::query()->first();
        $this->assertSame($evidence, $job->metadata);
        $this->assertSame('task-legacy-one', $job->task_id);
        $this->assertSame('source-legacy-one', $job->source_id);
        $this->assertSame('legacy-workflow', $job->temporal_workflow_id);
        $this->assertDatabaseHas('ingestion_sources', ['source_id' => $job->source_id, 'dataset_id' => 'web']);
        $this->assertDatabaseCount('pipeline_jobs', 1);
    }
}
