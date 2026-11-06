<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Models\Dataset;
use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Models\User;
use App\Services\Pipeline\CrawlerCallbackBoundary;
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
            'config.python_url_ingestion_url' => 'http://python.test',
            'config.query_bff' => $this->fixture->config->get('config.query_bff'),
        ]);
        Http::preventStrayRequests();
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
        Http::assertSentCount(1);
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
            'http://python.test/v1/pipeline/tasks*' => Http::response(['success' => true, 'tasks' => [['task_id' => 'python-task', 'started_at' => '2026-11-06T12:00:00Z']]]),
            'http://python.test/v1/pipeline/legacy-access' => Http::response(['success' => true, 'identities' => ['task-legacy-url']]),
        ]);
        $response = $this->getJson('/api/pipeline/tasks?limit=1')->assertOk()->assertJsonCount(1, 'tasks');
        $response->assertJsonPath('tasks.0.task_id', 'python-task');
        $this->assertDatabaseCount('pipeline_tasks', 1);
        $this->assertDatabaseMissing('pipeline_tasks', ['task_id' => 'python-task']);
    }
}
