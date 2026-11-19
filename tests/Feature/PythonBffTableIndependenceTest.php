<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\QueryBffFixture;
use Tests\TestCase;

/** Future-schema probes only: the application retains every compatibility table. */
class PythonBffTableIndependenceTest extends TestCase
{
    use RefreshDatabase;

    private QueryBffFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new QueryBffFixture;
        foreach (['query', 'text_ingestion', 'managed_document', 'crawler_ingestion', 'pipeline_execution', 'dataset', 'graph_ops'] as $owner) {
            config()->set('config.'.$owner.'_backend', 'python');
        }
        config()->set(['config.query_auth.all_datasets_by_default' => false, 'config.python_query_dataset_source' => 'database',
            'config.python_query_url' => 'http://python.test/v1/query', 'config.python_dataset_url' => 'http://python.test/v1/datasets',
            'config.python_graph_ops_url' => 'http://python.test/v1/ops', 'config.python_managed_document_url' => 'http://python.test',
            'config.python_url_ingestion_url' => 'http://python.test', 'config.python_text_ingestion_url' => 'http://python.test/v1/text-ingestions',
            'config.python_text_ingestion_events_url' => 'http://python.test/internal/text-ingestion/events',
            'config.query_bff' => $this->fixture->config->get('config.query_bff'),
            'temporal.enabled' => true, 'temporal.control_url' => 'http://python.test', 'temporal.callbacks.secret' => 'test-secret']);
        $this->actingAsApiUser(['query', 'admin', 'rag:text-ingest']);
        Http::preventStrayRequests();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        // These drops are confined to PHPUnit's guarded in-memory database.
        // There is deliberately no cleanup migration in this release.
        foreach (['rag_graph_failures', 'rag_ingestion_artifacts', 'pipeline_worker_events', 'pipeline_stage_states',
            'managed_document_outputs', 'managed_document_deletions', 'managed_documents', 'dataset_grant_tokens',
            'dataset_grants', 'scraped_elements', 'scrape_statistics', 'scrape_jobs', 'ingested_pages', 'documents',
            'ingestion_sources', 'pipeline_jobs', 'pipeline_tasks', 'datasets'] as $table) {
            Schema::drop($table);
        }
    }

    protected function tearDown(): void
    {
        $this->fixture->close();
        parent::tearDown();
    }

    #[TestWith([200])]
    #[TestWith([503])]
    public function test_default_query_and_upstream_failure_never_need_legacy_tables_or_bridge(int $status): void
    {
        $configuration = config('config');
        unset($configuration['query_backend']);
        config()->set('config', $configuration);
        Http::fake(['http://python.test/v1/query' => Http::response($status === 200
            ? ['ok' => true, 'count' => 0, 'hits' => [], 'kg' => [], 'answer' => 'Python', 'retrieval' => new \stdClass]
            : ['detail' => ['error' => 'query_unavailable', 'message' => 'Query execution is not available.']], $status)]);
        $this->postJson('/api/query', ['dataset_id' => 'only-python', 'query' => 'Q'])->assertStatus($status);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => ! str_starts_with($request->url(), 'http://python.test/'));
    }

    public function test_dataset_listing_uses_only_the_python_authority(): void
    {
        Http::fake(['http://python.test/v1/datasets*' => Http::response(['success' => true, 'datasets' => []])]);
        $this->getJson('/api/datasets')->assertOk()->assertExactJson(['success' => true, 'datasets' => []]);
        Http::assertSentCount(1);
    }

    public function test_graph_reads_use_python_without_a_local_dataset_or_neo4j_client(): void
    {
        Http::fake(['http://python.test/v1/ops/graph/overview*' => Http::response(['ok' => true, 'nodes' => [], 'edges' => []])]);
        $this->getJson('/api/rag/neo4j/graph/overview?dataset_id=only-python')->assertOk()->assertJsonPath('ok', true);
        Http::assertSentCount(1);
    }

    public function test_text_create_and_delete_do_not_reserve_local_pipeline_or_source_rows(): void
    {
        $source = 'source_'.str_repeat('a', 32);
        Http::fake(['http://python.test/v1/text-ingestions' => Http::response(['task_id' => 'task_text_'.str_repeat('a', 32), 'job_id' => 'ingest_'.str_repeat('b', 24),
            'source_id' => $source, 'workflow_id' => 'ingest-text-'.str_repeat('c', 32), 'status' => 'running', 'replayed' => false], 202),
            'http://python.test/v1/text-ingestions/'.$source => Http::response(['source_id' => $source, 'dataset_id' => 'only-python', 'status' => 'deleted', 'deleted' => true, 'replayed' => false])]);
        $this->withHeader('Idempotency-Key', 'text-key')->postJson('/api/integrations/text-ingestions',
            ['dataset_id' => 'only-python', 'external_document_id' => 'doc', 'text' => 'hello', 'content_format' => 'plain_text'])->assertAccepted();
        $this->deleteJson('/api/integrations/text-ingestions/'.$source)->assertOk();
        Http::assertSentCount(2);
    }

    public function test_current_managed_document_crud_does_not_enter_historical_compatibility(): void
    {
        Http::fake(function ($request) {
            $purpose = match ($request->method()) {
                'POST' => 'create', 'PUT' => 'update', 'DELETE' => 'delete', default => null
            };
            $body = ['success' => true, 'document' => ['document_id' => 'adoc_python', 'dataset_id' => 'only-python']];
            if ($purpose !== null) {
                $body['operation'] = ['type' => $purpose, 'status' => 'accepted'];
            }

            return Http::response($body, $purpose === null ? 200 : 202);
        });
        $this->withHeader('Idempotency-Key', 'managed-key')->post('/api/documents', ['dataset_id' => 'only-python',
            'file' => UploadedFile::fake()->createWithContent('report [final].txt', 'hello')], ['Accept' => 'application/json'])->assertAccepted();
        $this->getJson('/api/documents/adoc_python')->assertOk()->assertJsonPath('document.document_id', 'adoc_python');
        $this->put('/api/documents/adoc_python', ['force' => 'true', 'display_name' => 'Renamed',
            'file' => UploadedFile::fake()->createWithContent('replacement.txt', 'replacement')], ['Accept' => 'application/json'])->assertAccepted();
        $this->deleteJson('/api/documents/adoc_python')->assertAccepted();
        Http::assertSentCount(4);
    }

    public function test_new_pipeline_start_has_no_local_task_or_job_creation_path(): void
    {
        Http::fake(['http://python.test/internal/url-ingestion/readiness' => Http::response(['ready' => true]),
            'http://python.test/v1/pipeline/tasks/start' => Http::response(['success' => true, 'task_id' => 'task_python', 'task' => ['task_id' => 'task_python']], 201)]);
        $this->withHeader('Idempotency-Key', 'url-key')->postJson('/api/pipeline/tasks/start',
            ['dataset_id' => 'only-python', 'source_url' => 'https://example.test'])->assertCreated()->assertJsonPath('task_id', 'task_python');
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'workflow-control'));
    }

    public function test_dataset_storage_deletion_keeps_the_required_legacy_preflight_fail_closed(): void
    {
        $this->deleteJson('/api/datasets/only-python/storage')->assertStatus(503);
        Http::assertNothingSent();
    }
}
