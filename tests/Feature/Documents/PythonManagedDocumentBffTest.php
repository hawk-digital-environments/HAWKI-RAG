<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\Dataset;
use App\Models\IngestionSource;
use App\Models\ManagedDocument;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Models\User;
use App\Services\Document\ManagedDocumentSyncService;
use App\Services\Document\Repositories\ManagedDocumentRepository;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\Support\QueryBffFixture;
use Tests\TestCase;

final class PythonManagedDocumentBffTest extends TestCase
{
    use RefreshDatabase;

    private QueryBffFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new QueryBffFixture;
        config()->set([
            'config.managed_document_backend' => 'python',
            'config.python_managed_document_url' => 'http://python.test',
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

    private function accepted(string $purpose = 'create', string $status = 'accepted'): array
    {
        return ['success' => true, 'operation' => ['type' => $purpose, 'status' => $status], 'document' => ['document_id' => 'adoc_'.str_repeat('a', 32), 'dataset_id' => 'only-python'], 'pipeline' => ['task_id' => 'task_1', 'job_id' => 'job_1', 'source_id' => 'source_1']];
    }

    private function submit(array $fields = [], array $headers = [])
    {
        return $this->post('/api/documents', ['dataset_id' => 'only-python', 'file' => UploadedFile::fake()->createWithContent('Hello.TXT', 'hello'), ...$fields], ['Accept' => 'application/json', 'Idempotency-Key' => 'key-1', ...$headers]);
    }

    private function noLegacyWrites(): void
    {
        foreach (['managed_documents', 'managed_document_deletions', 'pipeline_tasks', 'pipeline_jobs', 'ingestion_sources'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/workflow-control/'));
    }

    public function test_single_user_upload_streams_multipart_with_exact_ingest_claims_and_no_local_authority(): void
    {
        $user = $this->user();
        Http::fake(['http://python.test/v1/documents' => Http::response($this->accepted(), 202)]);
        $this->submit(['graph' => 'true', 'metadata_json' => '{"origin":"test"}'])->assertAccepted();
        Http::assertSent(function ($request) use ($user): bool {
            $claims = JWT::decode(substr($request->header('Authorization')[0], 7), new Key($this->fixture->publicPem, 'RS256'));
            $fields = collect($request->data())->keyBy('name');

            return $request->isMultipart() && $request->header('Idempotency-Key') === ['key-1']
                && $claims->sub === (string) $user->id && $claims->scope === 'rag:ingest'
                && $claims->tenant_id === 'default' && $claims->azp === 'hawki-bff'
                && $claims->exp - $claims->iat <= 60
                && $fields['dataset_id']['contents'] === 'only-python'
                && $fields['graph']['contents'] === 'true';
        });
        $this->noLegacyWrites();
    }

    public function test_no_user_and_multiple_users_fail_closed(): void
    {
        $this->submit()->assertUnauthorized();
        $this->user();
        $this->user('two');
        $this->submit()->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_invalid_explicit_header_is_rejected_even_with_session(): void
    {
        $this->actingAs($this->user());
        $this->submit(headers: ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
        $this->submit(headers: ['Authorization' => 'Basic invalid'])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_session_and_valid_sanctum_principals_work_without_a_new_ability(): void
    {
        $user = $this->user();
        $this->user('two');
        Http::fake(['*' => Http::response($this->accepted(), 202)]);
        $this->submit(headers: ['Authorization' => 'Bearer '.$user->createToken('compatible', ['query'])->plainTextToken])->assertAccepted();
        $this->actingAs($user);
        $this->submit()->assertAccepted();
        $this->noLegacyWrites();
    }

    public function test_invalid_selector_and_python_failure_never_fallback(): void
    {
        $this->user();
        config()->set('config.managed_document_backend', 'invalid');
        $this->submit()->assertStatus(503);
        Http::assertNothingSent();
        config()->set('config.managed_document_backend', 'python');
        Http::fake(['*' => Http::response(['message' => 'secret database error'], 500)]);
        $this->submit()->assertStatus(502)->assertDontSee('secret');
        $this->noLegacyWrites();
    }

    public function test_python_put_and_delete_require_no_laravel_managed_handle(): void
    {
        $this->user();
        $id = 'adoc_'.str_repeat('a', 32);
        Http::fakeSequence()->push($this->accepted('update'), 202)->push($this->accepted('update', 'skipped_unchanged'), 200)->push($this->accepted('delete', 'pending'), 202)->push($this->accepted('delete', 'completed'), 200);
        foreach ([202, 200] as $status) {
            $this->put('/api/documents/'.$id, ['file' => UploadedFile::fake()->createWithContent('a.txt', 'hello'), 'force' => 'true'], ['Accept' => 'application/json'])->assertStatus($status);
        }
        $this->deleteJson('/api/documents/'.$id)->assertAccepted();
        $this->deleteJson('/api/documents/'.$id)->assertOk();
        $this->noLegacyWrites();
    }

    public function test_pipeline_aliases_and_custom_converter_use_same_managed_create(): void
    {
        $this->user();
        Http::fake(['*' => Http::response($this->accepted(), 202)]);
        $this->post('/api/pipeline/controller/files', ['datasetId' => 'only-python', 'converterMode' => 'custom', 'converterUrl' => 'https://converter.example/extract', 'converterToken' => 'private-converter-token', 'converterStartPath' => 'start', 'graph' => 'true', 'file' => UploadedFile::fake()->createWithContent('file.special', 'bytes')], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('document_id', 'adoc_'.str_repeat('a', 32))->assertJsonPath('task_id', 'task_1');
        Http::assertSent(function ($request): bool {
            $fields = collect($request->data())->keyBy('name');

            return $request->url() === 'http://python.test/v1/documents'
                && $fields['converter_mode']['contents'] === 'custom'
                && $fields['converter_start_path']['contents'] === '/start'
                && $fields['converter_token']['contents'] === 'private-converter-token';
        });
        $this->noLegacyWrites();
    }

    public function test_batch_preserves_partial_failure_envelope(): void
    {
        $this->user();
        $body = ['success' => false, 'operation' => ['type' => 'batch_create', 'status' => 'partial_failure'], 'summary' => ['total' => 2, 'accepted' => 1, 'failed' => 1], 'items' => [['index' => 0, 'file_name' => 'a.txt', 'http_status' => 202, 'success' => true, 'result' => $this->accepted()], ['index' => 1, 'file_name' => 'b.exe', 'http_status' => 422, 'success' => false, 'result' => ['success' => false, 'message' => 'Unsupported file.']]]];
        Http::fake(['*' => Http::response($body, 207)]);
        $this->post('/api/documents/batch', ['dataset_id' => 'only-python', 'files' => [UploadedFile::fake()->createWithContent('a.txt', 'one'), UploadedFile::fake()->createWithContent('b.exe', 'two')]], ['Accept' => 'application/json', 'Idempotency-Key' => 'batch'])->assertStatus(207)->assertExactJson($body);
        $this->noLegacyWrites();
    }

    private function callbackBody(bool $writer): string
    {
        $identity = ['schema_version' => 1, 'event_id' => 'callback-1', 'workflow_id' => 'ingest-source-source_'.str_repeat('a', 32), 'run_id' => 'run-1', 'source_id' => 'source_'.str_repeat('a', 32), 'job_id' => 'job-1', 'activity_id' => $writer ? '1' : 'mark_source_ready', 'attempt' => 1];

        return json_encode($writer ? [...$identity, 'action' => 'acquire', 'targets' => []] : [...$identity, 'event_type' => 'pipeline.stage.status', 'producer' => 'indexer', 'timestamp' => gmdate(DATE_ATOM), 'task_id' => 'task-1', 'stage' => 'ingest', 'phase' => 'mark_source_ready', 'status' => 'completed', 'counts' => [], 'metrics' => [], 'artifacts' => [], 'errors' => [], 'warnings' => []], JSON_THROW_ON_ERROR);
    }

    private function signedCallback(string $body, bool $writer = false)
    {
        $stamp = (string) time();

        return $this->call('POST', '/api/internal/pipeline/'.($writer ? 'managed-writers' : 'worker-events'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_HAWKI_TIMESTAMP' => $stamp,
            'HTTP_X_HAWKI_SIGNATURE' => 'v1='.hash_hmac('sha256', $stamp.'.'.$body, 'workflow-control-test-secret'),
        ], $body);
    }

    public function test_managed_event_and_writer_relays_preserve_exact_signed_bytes_and_only_python_state(): void
    {
        foreach ([false, true] as $writer) {
            $body = $this->callbackBody($writer);
            $receipt = $writer ? ['success' => true, 'status' => 'active', 'targets' => []] : ['accepted' => true, 'duplicate' => false, 'ignored' => false, 'event_id' => 'callback-1'];
            Http::fake([
                '*/ownership' => Http::response(['owner' => 'python']),
                '*/'.($writer ? 'writers' : 'events') => Http::response($receipt, $writer ? 200 : 202),
            ]);
            $this->signedCallback($body, $writer)->assertStatus($writer ? 200 : 202)->assertExactJson($receipt);
            Http::assertSent(fn ($request): bool => $request->url() === 'http://python.test/internal/managed-documents/'.($writer ? 'writers' : 'events') && $request->body() === $body
                && $request->header('X-Hawki-Signature')[0] === 'v1='.hash_hmac('sha256', $request->header('X-Hawki-Timestamp')[0].'.'.$body, 'workflow-control-test-secret'));
        }
        $this->noLegacyWrites();
    }

    public function test_unknown_callback_ownership_fails_closed_without_laravel_fallback(): void
    {
        Http::fake(['*/ownership' => Http::response(['message' => 'unknown'], 409)]);
        $this->signedCallback($this->callbackBody(false))->assertStatus(409);
        $this->assertDatabaseCount('pipeline_worker_events', 0);
        $this->noLegacyWrites();
    }

    public function test_proven_crawler_callback_still_uses_laravel_handler_in_python_mode(): void
    {
        Http::fake(['*/ownership' => Http::response(['owner' => 'laravel'])]);
        $event = json_decode($this->callbackBody(false), true);
        Dataset::query()->create(['dataset_id' => 'crawler', 'name' => 'crawler', 'status' => 'active', 'qdrant_collection' => 'crawler-vectors', 'neo4j_namespace' => 'crawler-graph']);
        PipelineTask::query()->create(['task_id' => 'task-1', 'dataset_id' => 'crawler', 'status' => 'running']);
        IngestionSource::query()->create(['source_id' => $event['source_id'], 'task_id' => 'task-1', 'dataset_id' => 'crawler', 'source_url' => 'https://crawler.example/page', 'index_status' => 'running', 'temporal_workflow_id' => $event['workflow_id'], 'metadata' => ['temporal' => ['run_id' => 'run-1']]]);
        PipelineJob::query()->create(['job_id' => 'job-1', 'task_id' => 'task-1', 'source_id' => $event['source_id'], 'job_type' => 'ingest', 'status' => 'running', 'temporal_workflow_id' => $event['workflow_id'], 'temporal_run_id' => 'run-1']);
        $this->signedCallback($this->callbackBody(false))->assertAccepted();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('pipeline_worker_events', 1);
        $this->assertDatabaseHas('ingestion_sources', ['source_id' => $event['source_id'], 'index_status' => 'ready']);
    }

    public function test_public_upload_limit_is_inclusive_and_explicit_null_metadata_is_forwarded(): void
    {
        $this->user();
        Http::fake(['*' => Http::response($this->accepted(), 202)]);
        $this->submit(['file' => UploadedFile::fake()->create('a.txt', 102400), 'metadata_json' => ''])->assertAccepted();
        Http::assertSent(fn ($request): bool => collect($request->data())->keyBy('name')['metadata_json']['contents'] === '');
        $this->submit(['file' => UploadedFile::fake()->create('a.txt', 102401)])->assertUnprocessable();
        Http::assertSentCount(1);
        $this->noLegacyWrites();
    }

    public function test_known_upload_failure_shapes_remain_compatible(): void
    {
        $this->user();
        $unsupported = ['success' => false, 'message' => 'This file type is not accepted by RAGAnything native ingestion. Enable Custom converter for special formats.', 'accepted_extensions' => ['pdf', 'txt']];
        $storage = ['success' => false, 'message' => 'The uploaded file could not be stored. No dataset, task, or job was created.', 'dataset_id' => 'only-python', 'task_id' => null, 'job_id' => null, 'error' => null];
        Http::fakeSequence()->push($unsupported, 422)->push($storage, 500);
        $this->submit()->assertUnprocessable()->assertExactJson($unsupported);
        $this->submit()->assertStatus(500)->assertExactJson($storage);
        $this->noLegacyWrites();
    }

    private function forbidLegacyProjection(): void
    {
        $this->app->instance(ManagedDocumentSyncService::class, new readonly class extends ManagedDocumentSyncService
        {
            public function __construct() {}

            public function sync(ManagedDocument $document): ManagedDocument
            {
                throw new \LogicException('Python ownership must never invoke legacy synchronization.');
            }
        });
    }

    public function test_post_then_get_and_list_use_python_without_legacy_projection(): void
    {
        $this->user();
        $this->forbidLegacyProjection();
        $document = $this->accepted()['document'];
        Http::fake(function ($request) use ($document) {
            if ($request->method() === 'POST') {
                return Http::response($this->accepted(), 202);
            }
            $claims = JWT::decode(substr($request->header('Authorization')[0], 7), new Key($this->fixture->publicPem, 'RS256'));
            $this->assertSame('rag:query', $claims->scope);

            return Http::response(str_contains($request->url(), '/v1/documents/') ? ['success' => true, 'document' => $document] : ['success' => true, 'documents' => [$document]]);
        });
        $id = $this->submit()->assertAccepted()->json('document.document_id');
        $this->getJson('/api/documents/'.$id)->assertOk()->assertJsonPath('document.document_id', $id);
        $this->getJson('/api/documents?datasetId=only-python&search=test&limit=2')->assertOk()->assertJsonPath('documents.0.document_id', $id);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET' && str_contains($request->url(), 'datasetId=only-python'));
        $this->noLegacyWrites();
    }

    public function test_reads_reflect_python_lifecycle_even_with_a_colliding_legacy_row(): void
    {
        $this->user();
        $document = $this->accepted()['document'];
        ManagedDocument::query()->create(['document_id' => $document['document_id'], 'dataset_id' => 'legacy', 'source_type' => 'upload', 'status' => 'failed']);
        $this->forbidLegacyProjection();
        Http::fakeSequence()->push(['success' => true, 'document' => [...$document, 'status' => 'processing']])
            ->push(['success' => true, 'document' => [...$document, 'status' => 'indexed']]);
        $this->getJson('/api/documents/'.$document['document_id'])->assertJsonPath('document.status', 'processing');
        $this->getJson('/api/documents/'.$document['document_id'])->assertJsonPath('document.status', 'indexed');
    }

    public function test_python_denial_cannot_fall_back_to_a_legacy_copy(): void
    {
        $this->user();
        ManagedDocument::query()->create(['document_id' => 'adoc_collision', 'dataset_id' => 'legacy', 'source_type' => 'upload', 'status' => 'indexed']);
        $this->forbidLegacyProjection();
        Http::fake(['*/read-access' => Http::response(['legacy_document_ids' => []]), '*' => Http::response(['success' => false], 404)]);
        $this->getJson('/api/documents/adoc_collision')->assertNotFound();
    }

    public function test_proven_legacy_reads_remain_available_in_python_mode(): void
    {
        $this->user();
        ManagedDocument::query()->create(['document_id' => 'adoc_legacy', 'dataset_id' => 'legacy', 'source_type' => 'upload', 'status' => 'indexed']);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/read-access')) {
                return Http::response(['legacy_document_ids' => ['adoc_legacy']]);
            }

            return str_contains($request->url(), '/v1/documents/') ? Http::response(['success' => false], 404) : Http::response(['success' => true, 'documents' => []]);
        });
        $this->getJson('/api/documents/adoc_legacy')->assertOk()->assertJsonPath('document.document_id', 'adoc_legacy');
        $this->getJson('/api/documents')->assertOk()->assertJsonPath('documents.0.document_id', 'adoc_legacy');
    }

    public function test_denied_legacy_list_candidates_are_never_projected(): void
    {
        $this->user();
        ManagedDocument::query()->create(['document_id' => 'adoc_denied', 'dataset_id' => 'denied', 'source_type' => 'upload', 'status' => 'indexed']);
        $this->forbidLegacyProjection();
        Http::fake(['*/read-access' => Http::response(['legacy_document_ids' => []]), '*' => Http::response(['success' => true, 'documents' => []])]);
        $this->getJson('/api/documents')->assertOk()->assertJsonCount(0, 'documents');
    }

    public function test_mixed_list_has_one_limit_and_excludes_python_owned_legacy_copies(): void
    {
        $this->user();
        $python = [...$this->accepted()['document'], 'indexed_at' => '2026-11-05T10:00:00Z'];
        foreach ([$python['document_id'], 'adoc_legacy'] as $id) {
            ManagedDocument::query()->create(['document_id' => $id, 'dataset_id' => 'legacy', 'source_type' => 'upload', 'status' => 'indexed', 'indexed_at' => '2026-11-04T10:00:00Z']);
        }
        Http::fake(['*/read-access' => Http::response(['legacy_document_ids' => ['adoc_legacy']]), '*' => Http::response(['success' => true, 'documents' => [$python]])]);
        $this->getJson('/api/documents?limit=1')->assertOk()->assertJsonCount(1, 'documents')->assertJsonPath('documents.0.document_id', $python['document_id']);
        $this->getJson('/api/documents?limit=2')->assertOk()->assertJsonCount(2, 'documents');
    }

    public function test_mixed_list_includes_the_top_legacy_row_beyond_250_null_timestamps(): void
    {
        $this->user();
        // SQLite defaults to NULLS LAST for DESC. Exercise PostgreSQL's actual
        // candidate ordering explicitly without changing the final list sort.
        $this->app->instance(ManagedDocumentRepository::class, new readonly class extends ManagedDocumentRepository
        {
            public function list(array $filters, int $limit, int $offset = 0): Collection
            {
                return ManagedDocument::query()->with('outputs')
                    ->whereNull('deleted_at')
                    ->orderByRaw('indexed_at DESC NULLS FIRST')
                    ->orderByDesc('updated_at')->orderByDesc('created_at')
                    ->orderBy('document_id')->offset($offset)->limit($limit)->get();
            }
        });
        $this->seedLegacyCandidates(null);
        ManagedDocument::query()->create(['document_id' => 'adoc_legacy_winner', 'dataset_id' => 'legacy', 'source_type' => 'upload', 'status' => 'indexed', 'indexed_at' => '2026-11-05T10:00:00Z']);
        $this->fakeMixedList();

        $this->getJson('/api/documents?limit=1')->assertOk()->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.document_id', 'adoc_legacy_winner');
        Http::assertSentCount(3);
    }

    public function test_mixed_list_resolves_legacy_sync_timestamps_before_excluding_later_candidates(): void
    {
        $this->user();
        $this->seedLegacyCandidates('2026-11-03T10:00:00Z');
        Dataset::query()->create(['dataset_id' => 'legacy', 'name' => 'legacy', 'status' => 'active', 'qdrant_collection' => 'legacy-vectors', 'neo4j_namespace' => 'legacy-graph']);
        PipelineTask::query()->create(['task_id' => 'legacy-task', 'dataset_id' => 'legacy', 'status' => 'running']);
        IngestionSource::query()->create(['source_id' => 'legacy-ready', 'task_id' => 'legacy-task', 'dataset_id' => 'legacy', 'source_url' => 'upload://legacy.txt', 'index_status' => 'ready', 'ready_at' => '2026-11-05T10:00:00Z']);
        ManagedDocument::query()->create(['document_id' => 'adoc_legacy_winner', 'dataset_id' => 'legacy', 'source_type' => 'upload', 'status' => 'processing', 'latest_source_id' => 'legacy-ready', 'indexed_at' => '2026-11-01T10:00:00Z']);
        $this->fakeMixedList();

        $this->getJson('/api/documents?limit=1')->assertOk()->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.document_id', 'adoc_legacy_winner')
            ->assertJsonPath('documents.0.status', 'indexed');
    }

    public function test_expanded_legacy_candidates_exclude_denied_and_python_owned_rows_before_sync(): void
    {
        $this->user();
        $this->seedLegacyCandidates(null);
        ManagedDocument::query()->update(['dataset_id' => 'denied']);
        $pythonId = $this->accepted()['document']['document_id'];
        ManagedDocument::query()->create(['document_id' => $pythonId, 'dataset_id' => 'legacy', 'source_type' => 'upload', 'status' => 'failed']);
        ManagedDocument::query()->create(['document_id' => 'adoc_legacy_winner', 'dataset_id' => 'legacy', 'source_type' => 'upload', 'status' => 'indexed', 'indexed_at' => '2026-11-05T10:00:00Z']);
        $this->app->instance(ManagedDocumentSyncService::class, new readonly class($this->app->make(ManagedDocumentSyncService::class), $pythonId) extends ManagedDocumentSyncService
        {
            public function __construct(private ManagedDocumentSyncService $delegate, private string $pythonId) {}

            public function sync(ManagedDocument $document): ManagedDocument
            {
                if ($document->dataset_id === 'denied' || $document->document_id === $this->pythonId) {
                    throw new \LogicException('Denied or Python-owned candidate must not be synchronized.');
                }

                return $this->delegate->sync($document);
            }
        });
        $this->fakeMixedList();

        $this->getJson('/api/documents?limit=250')->assertOk()->assertJsonCount(2, 'documents')
            ->assertJsonPath('documents.0.document_id', 'adoc_legacy_winner')
            ->assertJsonPath('documents.1.document_id', $pythonId);
        Http::assertSentCount(3);
    }

    public function test_mixed_list_uses_timestamp_tie_breakers_nulls_last_and_id_across_owners(): void
    {
        $this->user();
        $base = ['dataset_id' => 'legacy', 'source_type' => 'upload', 'status' => 'indexed', 'indexed_at' => '2026-11-04 10:00:00', 'updated_at' => '2026-11-03 10:00:00', 'created_at' => '2026-11-01 10:00:00'];
        foreach ([
            ['document_id' => 'adoc_null_index', 'indexed_at' => null, 'updated_at' => '2026-11-06 10:00:00'],
            ['document_id' => 'adoc_null_updated', 'updated_at' => null],
            ['document_id' => 'adoc_a'],
            ['document_id' => 'adoc_newer_created', 'created_at' => '2026-11-02 10:00:00'],
        ] as $row) {
            ManagedDocument::query()->insert([...$base, ...$row]);
        }
        $python = [
            [...$base, 'document_id' => 'adoc_b'],
            [...$base, 'document_id' => 'adoc_newer_updated', 'updated_at' => '2026-11-04 10:00:00'],
            [...$base, 'document_id' => 'adoc_null_created', 'created_at' => null],
            [...$base, 'document_id' => 'adoc_newest_index', 'indexed_at' => '2026-11-05 10:00:00', 'updated_at' => null, 'created_at' => null],
        ];
        Http::fake(function ($request) use ($python) {
            return str_ends_with($request->url(), '/read-access')
                ? Http::response(['legacy_document_ids' => array_column($request->data()['documents'], 'document_id')])
                : Http::response(['success' => true, 'documents' => $python]);
        });

        $expected = ['adoc_newest_index', 'adoc_newer_updated', 'adoc_newer_created', 'adoc_a', 'adoc_b', 'adoc_null_created', 'adoc_null_updated', 'adoc_null_index'];
        foreach ([8, 3] as $limit) {
            $rows = $this->getJson('/api/documents?limit='.$limit)->assertOk()->assertJsonCount($limit, 'documents')->json('documents');
            $this->assertSame(array_slice($expected, 0, $limit), array_column($rows, 'document_id'));
        }
    }

    public function test_later_legacy_access_batch_failure_returns_no_partial_list(): void
    {
        $this->user();
        $this->seedLegacyCandidates(null);
        ManagedDocument::query()->create(['document_id' => 'adoc_last', 'dataset_id' => 'legacy', 'source_type' => 'upload', 'status' => 'indexed']);
        Http::fakeSequence()->push(['success' => true, 'documents' => []])
            ->push(['legacy_document_ids' => ['adoc_candidate_000']])
            ->push(['success' => false], 503);

        $this->getJson('/api/documents?limit=1')->assertStatus(503)->assertJsonMissingPath('documents');
        Http::assertSentCount(3);
    }

    public function test_expanded_legacy_candidates_keep_dataset_and_search_filters(): void
    {
        $this->user();
        foreach ([['adoc_wanted', 'legacy', 'match'], ['adoc_other_dataset', 'other', 'match'], ['adoc_other_name', 'legacy', 'different']] as [$id, $dataset, $name]) {
            ManagedDocument::query()->create(['document_id' => $id, 'dataset_id' => $dataset, 'display_name' => $name, 'source_type' => 'upload', 'status' => 'indexed']);
        }
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/read-access')) {
                $this->assertSame(['adoc_wanted'], array_column($request->data()['documents'], 'document_id'));

                return Http::response(['legacy_document_ids' => ['adoc_wanted']]);
            }

            return Http::response(['success' => true, 'documents' => []]);
        });

        $this->getJson('/api/documents?datasetId=legacy&search=match&limit=1')->assertOk()
            ->assertJsonCount(1, 'documents')->assertJsonPath('documents.0.document_id', 'adoc_wanted');
    }

    private function seedLegacyCandidates(?string $indexedAt): void
    {
        $rows = [];
        for ($index = 0; $index < 250; $index++) {
            $rows[] = ['document_id' => sprintf('adoc_candidate_%03d', $index), 'dataset_id' => 'legacy', 'source_type' => 'upload', 'status' => $indexedAt === null ? 'processing' : 'indexed', 'indexed_at' => $indexedAt, 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00'];
        }
        ManagedDocument::query()->insert($rows);
    }

    private function fakeMixedList(): void
    {
        $python = [...$this->accepted()['document'], 'indexed_at' => '2026-11-04T10:00:00Z'];
        Http::fake(function ($request) use ($python) {
            if (str_ends_with($request->url(), '/read-access')) {
                $candidates = $request->data()['documents'];
                $this->assertLessThanOrEqual(250, count($candidates));
                $this->assertTrue(array_is_list($candidates));
                $allowed = array_filter($candidates, static fn ($row): bool => $row['dataset_id'] === 'legacy' && $row['document_id'] !== $python['document_id']);

                return Http::response(['legacy_document_ids' => array_values(array_column($allowed, 'document_id'))]);
            }

            return Http::response(['success' => true, 'documents' => [$python]]);
        });
    }

    public function test_read_principal_unknown_id_and_outage_fail_closed(): void
    {
        $this->getJson('/api/documents')->assertUnauthorized();
        $user = $this->user();
        $this->getJson('/api/documents', ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
        $this->getJson('/api/documents', ['Authorization' => 'Bearer '.$user->createToken('ingest-only', ['ingest'])->plainTextToken])->assertUnauthorized();
        $this->actingAs($user->fresh());
        Http::fakeSequence()->push(['success' => false], 404)->push(['private' => 'upstream failure'], 500);
        $this->getJson('/api/documents/adoc_unknown')->assertNotFound()->assertJsonPath('message', 'Document adoc_unknown was not found.');
        $this->getJson('/api/documents')->assertStatus(502)->assertDontSee('upstream failure');
    }

    public function test_legacy_mode_list_and_show_do_not_contact_python(): void
    {
        config()->set('config.managed_document_backend', 'laravel');
        ManagedDocument::query()->create(['document_id' => 'adoc_legacy', 'dataset_id' => 'legacy', 'source_type' => 'upload', 'status' => 'indexed']);
        $this->getJson('/api/documents/adoc_legacy')->assertOk()->assertJsonPath('document.document_id', 'adoc_legacy');
        $this->getJson('/api/documents')->assertOk()->assertJsonPath('documents.0.document_id', 'adoc_legacy');
        Http::assertNothingSent();
    }

    public function test_internal_jwt_is_never_reflected_even_when_upstream_json_escapes_it(): void
    {
        $this->user();
        Http::fake(function ($request) {
            $token = substr($request->header('Authorization')[0], 7);
            $body = $this->accepted();
            $body['document']['private'] = $token;

            return Http::response(str_replace('.', '\\u002e', json_encode($body, JSON_THROW_ON_ERROR)), 202, ['Content-Type' => 'application/json']);
        });
        $this->submit()->assertStatus(502)->assertDontSee('private');
        $this->noLegacyWrites();
    }
}
