<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\QueryBffFixture;
use Tests\TestCase;

final class PythonTextIngestionBffTest extends TestCase
{
    use RefreshDatabase;

    private QueryBffFixture $fixture;

    private User $user;

    private string $token;

    private const SOURCE = 'source_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new QueryBffFixture;
        config()->set([
            'config.text_ingestion_backend' => 'python',
            'config.python_text_ingestion_url' => 'http://python.test/v1/text-ingestions',
            'config.python_text_ingestion_events_url' => 'http://python.test/internal/text-ingestion/events',
            'config.query_bff' => $this->fixture->config->get('config.query_bff'),
            'temporal.callbacks.secret' => 'event-test-secret',
            'temporal.storage.shared_root' => storage_path('framework/testing/python-bff-no-artifacts'),
        ]);
        $this->user = User::query()->create(['username' => 'python-bff', 'email' => 'python-bff@example.test', 'ip' => '127.0.0.91']);
        $this->token = $this->user->createToken('ingest', ['rag:text-ingest'])->plainTextToken;
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->fixture->close();
        parent::tearDown();
    }

    private function response(bool $replayed = false): array
    {
        return ['task_id' => 'task_text_'.str_repeat('a', 32), 'job_id' => 'ingest_'.str_repeat('b', 24), 'source_id' => self::SOURCE,
            'workflow_id' => 'ingest-text-'.str_repeat('c', 32), 'status' => 'running', 'replayed' => $replayed];
    }

    private function noLegacyWrites(): void
    {
        foreach (['pipeline_tasks', 'pipeline_jobs', 'ingestion_sources', 'pipeline_worker_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDirectoryDoesNotExist(config('temporal.storage.shared_root').'/sources');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'workflow-control'));
    }

    private function submit(string $body = '{"dataset_id":"only-in-python","text":" raw ","external_document_id":"doc","content_format":"plain_text"}')
    {
        return $this->call('POST', '/api/integrations/text-ingestions', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'HTTP_IDEMPOTENCY_KEY' => 'key-1',
        ], $body);
    }

    public function test_submission_and_replay_forward_exact_body_and_ingest_jwt_without_local_grants(): void
    {
        Http::fakeSequence()->push($this->response(), 202)->push($this->response(true), 200);
        $body = '{ "text":" raw ","metadata":{"object":{}},"dataset_id":"only-in-python","external_document_id":"doc","content_format":"plain_text" }';
        $this->submit($body)->assertAccepted();
        $this->submit($body)->assertOk()->assertJsonPath('replayed', true);
        Http::assertSent(function ($request) use ($body) {
            $claims = JWT::decode(substr($request->header('Authorization')[0], 7), new Key($this->fixture->publicPem, 'RS256'));

            return $request->body() === $body && $request->header('Idempotency-Key') === ['key-1']
                && $claims->sub === (string) $this->user->id && $claims->scope === 'rag:ingest'
                && $claims->tenant_id === 'default' && $claims->azp === 'hawki-bff';
        });
        $this->noLegacyWrites();
    }

    #[DataProvider('errors')]
    public function test_safe_error_mapping(int $status, string $code, string $message): void
    {
        Http::fake(['*' => Http::response(['detail' => ['error' => $code, 'message' => $message]], $status)]);
        $this->submit('{"graph":true}')->assertStatus($status)->assertJsonPath('error', $code);
        $this->noLegacyWrites();
    }

    public static function errors(): array
    {
        return [
            [422, 'text_ingestion_invalid_request', 'The given data was invalid.'],
            [409, 'text_ingestion_idempotency_conflict', 'The idempotency key was already used with a different request.'],
            [413, 'text_ingestion_request_too_large', 'The text ingestion request is too large.'],
            [502, 'text_ingestion_workflow_unconfirmed', 'The text ingestion workflow could not be confirmed. Retry with the same idempotency key.'],
            [503, 'text_ingestion_unavailable', 'Text ingestion is not available.'],
        ];
    }

    public function test_transport_failure_does_not_fallback(): void
    {
        Http::fake(fn () => throw new ConnectionException('private endpoint secret'));
        $this->submit()->assertStatus(502)->assertDontSee('private endpoint');
        $this->noLegacyWrites();
    }

    public function test_untrusted_upstream_error_is_not_exposed(): void
    {
        Http::fake(['*' => Http::response(['detail' => ['error' => 'unknown', 'message' => 'secret /shared/path']], 422)]);
        $this->submit()->assertStatus(502)->assertDontSee('secret');
    }

    public function test_auth_and_exact_ability_remain_required(): void
    {
        $this->postJson('/api/integrations/text-ingestions', [])->assertUnauthorized();
        $this->token = $this->user->createToken('query', ['rag:query'])->plainTextToken;
        $this->submit()->assertForbidden();
        Http::assertNothingSent();
        $this->noLegacyWrites();
    }

    public function test_invalid_selector_fails_closed_before_validation_or_proxy(): void
    {
        config()->set('config.text_ingestion_backend', 'typo');
        $this->submit('{}')->assertStatus(503);
        Http::assertNothingSent();
        $this->noLegacyWrites();
    }

    public function test_defensive_raw_limit_remains_in_bff(): void
    {
        $this->submit(str_repeat(' ', 4_300_001))->assertStatus(413);
        Http::assertNothingSent();
    }

    #[DataProvider('deleteCases')]
    public function test_delete_is_python_owned(int $status, array $body): void
    {
        Http::fake(['*' => Http::response($body, $status)]);
        $this->withToken($this->token)->deleteJson('/api/integrations/text-ingestions/'.self::SOURCE, [], ['Idempotency-Key' => 'delete-1'])->assertStatus($status);
        $this->noLegacyWrites();
    }

    public static function deleteCases(): array
    {
        $success = ['source_id' => self::SOURCE, 'dataset_id' => 'only-in-python', 'status' => 'deleted', 'deleted' => true, 'replayed' => false];

        return [
            [200, $success], [200, [...$success, 'replayed' => true]],
            [404, ['detail' => ['error' => 'text_ingestion_not_found', 'message' => 'The requested direct-text ingestion is not available.']]],
            [409, ['detail' => ['error' => 'text_ingestion_source_busy', 'message' => 'The direct-text source is already being processed.']]],
            [502, ['detail' => ['error' => 'text_ingestion_deletion_failed', 'message' => 'Direct-text deletion could not be completed. Retry with the same idempotency key.']]],
        ];
    }

    #[DataProvider('relayCases')]
    public function test_signed_event_relay_preserves_bytes_and_id(bool $failure): void
    {
        $receipt = ['event_id' => 'event-1', 'accepted' => true, 'duplicate' => false, 'ignored' => false];
        Http::fake(['*' => Http::response($failure ? ['message' => 'private storage'] : $receipt, $failure ? 500 : 202)]);
        $body = json_encode(['schema_version' => 1, 'event_id' => 'event-1', 'event_type' => 'pipeline.stage.status', 'producer' => 'indexer',
            'timestamp' => gmdate(DATE_ATOM), 'workflow_id' => 'ingest-text-'.str_repeat('c', 32), 'run_id' => 'run-1',
            'activity_id' => 'mark_source_ready', 'attempt' => 1, 'job_id' => 'job-1', 'task_id' => 'task-1', 'source_id' => self::SOURCE,
            'stage' => 'ingest', 'phase' => 'mark_source_ready', 'status' => 'completed', 'counts' => [], 'metrics' => [],
            'artifacts' => [], 'errors' => [], 'warnings' => []], JSON_THROW_ON_ERROR);
        $stamp = (string) time();
        $this->call('POST', '/api/internal/pipeline/worker-events', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_HAWKI_TIMESTAMP' => $stamp,
            'HTTP_X_HAWKI_SIGNATURE' => 'v1='.hash_hmac('sha256', $stamp.'.'.$body, 'event-test-secret'),
        ], $body)->assertStatus($failure ? 503 : 202)->assertDontSee('private storage');
        Http::assertSent(fn ($request) => $request->body() === $body
            && $request->header('X-Hawki-Signature')[0] === 'v1='.hash_hmac('sha256', $request->header('X-Hawki-Timestamp')[0].'.'.$body, 'event-test-secret'));
        $this->noLegacyWrites();
    }

    public static function relayCases(): array
    {
        return [[false], [true]];
    }

    public function test_signed_text_writer_is_relayed_without_local_writer_state(): void
    {
        $receipt = ['success' => true, 'status' => 'active', 'targets' => [
            ['doc_id' => 'doc-1', 'source_id' => self::SOURCE, 'dataset_id' => 'only-in-python', 'collection' => 'historical', 'neo4j_namespace' => null],
        ]];
        Http::fakeSequence()->push($receipt, 200)->push(['message' => 'private'], 503);
        $body = json_encode(['schema_version' => 1, 'event_id' => 'writer-1', 'action' => 'acquire',
            'workflow_id' => 'ingest-text-'.str_repeat('c', 32), 'run_id' => 'run-1',
            'activity_id' => '1', 'attempt' => 1, 'source_id' => self::SOURCE, 'job_id' => 'job-1',
            'targets' => [['doc_id' => 'doc-1', 'relative_path' => 'document.md', 'graph' => false]],
        ], JSON_THROW_ON_ERROR);
        $stamp = (string) time();
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HAWKI_TIMESTAMP' => $stamp, 'HTTP_X_HAWKI_SIGNATURE' => 'v1='.hash_hmac('sha256', $stamp.'.'.$body, 'event-test-secret')];
        $this->call('POST', '/api/internal/pipeline/managed-writers', [], [], [], $server, $body)->assertOk()->assertExactJson($receipt);
        Http::assertSent(fn ($request) => $request->url() === 'http://python.test/internal/text-ingestion/writers'
            && $request->body() === $body
            && $request->header('X-Hawki-Signature')[0] === 'v1='.hash_hmac('sha256', $request->header('X-Hawki-Timestamp')[0].'.'.$body, 'event-test-secret'));
        $this->noLegacyWrites();
        $this->call('POST', '/api/internal/pipeline/managed-writers', [], [], [], $server, $body)->assertStatus(503)->assertDontSee('private');
        $server['HTTP_X_HAWKI_SIGNATURE'] = 'v1='.str_repeat('0', 64);
        $this->call('POST', '/api/internal/pipeline/managed-writers', [], [], [], $server, $body)->assertUnauthorized();
        Http::assertSentCount(2);
        $this->noLegacyWrites();
    }
}
