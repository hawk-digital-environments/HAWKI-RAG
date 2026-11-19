<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\HawkiRagServer;
use App\Mcp\Tools\HawkiRagSearchTool;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Graph\GraphOpsBackend;
use App\Services\Pipeline\LegacyDrainStatusService;
use App\Services\Pipeline\PipelineExecutionBackend;
use App\Services\Pipeline\Repositories\PipelineOwnershipRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\QueryBffFixture;
use Tests\TestCase;

class Epoch15aCutoverTest extends TestCase
{
    use RefreshDatabase;

    private const SELECTORS = [
        'HAWKI_QUERY_BACKEND' => 'query_backend',
        'TEXT_INGESTION_BACKEND' => 'text_ingestion_backend',
        'MANAGED_DOCUMENT_BACKEND' => 'managed_document_backend',
        'CRAWLER_INGESTION_BACKEND' => 'crawler_ingestion_backend',
        'PIPELINE_EXECUTION_BACKEND' => 'pipeline_execution_backend',
        'DATASET_BACKEND' => 'dataset_backend',
        'GRAPH_OPS_BACKEND' => 'graph_ops_backend',
    ];

    public function test_checked_in_defaults_use_python_and_explicit_rollback_overrides_survive(): void
    {
        $names = [...array_keys(self::SELECTORS), 'HAWKI_RAG_QUERY_ALL_DATASETS_BY_DEFAULT', 'HAWKI_BACKEND_QUERY_DATASET_SOURCE'];
        $saved = [];
        foreach ($names as $name) {
            $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
        try {
            $defaults = require base_path('config/config.php');
            foreach (self::SELECTORS as $key) {
                $this->assertSame('python', $defaults[$key]);
            }
            $this->assertFalse($defaults['query_auth']['all_datasets_by_default']);
            $this->assertSame('database', $defaults['python_query_dataset_source']);
            foreach (self::SELECTORS as $name => $key) {
                $_ENV[$name] = $_SERVER[$name] = 'laravel';
                putenv($name.'=laravel');
            }
            $rollback = require base_path('config/config.php');
            foreach (self::SELECTORS as $key) {
                $this->assertSame('laravel', $rollback[$key]);
            }
        } finally {
            foreach ($saved as $name => [$env, $server, $process]) {
                unset($_ENV[$name], $_SERVER[$name]);
                if ($env !== null) {
                    $_ENV[$name] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
                putenv($process === false ? $name : $name.'='.$process);
            }
        }
    }

    public function test_missing_runtime_config_cannot_restore_legacy_authority(): void
    {
        config()->set('config', []);
        Http::fake();
        $this->assertSame('python', app(GraphOpsBackend::class)->value());
        $this->assertSame('python', app(PipelineExecutionBackend::class)->value());
        $this->getJson('/api/rag/health')->assertStatus(503);
        $this->postJson('/api/pipeline/tasks/start', [])->assertStatus(503);
        $this->putJson('/api/settings/config', [])->assertStatus(409);
        Http::assertNothingSent();
        $this->assertDatabaseCount('pipeline_tasks', 0);
    }

    public function test_raw_execution_and_collection_delete_routes_are_unregistered(): void
    {
        $uris = array_map(static fn (Route $route): string => $route->uri(), $this->app['router']->getRoutes()->getRoutes());
        foreach (['api/scraper/jobs', 'api/scraper/status/{jobId}', 'api/scraper/tasks/start',
            'api/scraper/jobs/{jobId}/cancel', 'api/scraper/jobs/{jobId}/pause', 'api/scraper/jobs/{jobId}/resume',
            'api/rag/qdrant/collections/{collection}'] as $uri) {
            $this->assertNotContains($uri, $uris);
        }
        $this->assertContains('api/scraper/tasks', $uris);
        foreach (['worker-events', 'managed-writers', 'managed-deletion-events'] as $callback) {
            $this->assertContains('api/internal/pipeline/'.$callback, $uris);
        }
        $this->assertNotContains(HawkiRagSearchTool::class, (new \ReflectionClass(HawkiRagServer::class))->getDefaultProperties()['tools']);
    }

    public function test_unknown_and_cancelled_statuses_are_not_drained_and_command_performs_no_writes(): void
    {
        foreach (['completed', 'failed', 'skipped', 'cancelled', 'future-state'] as $status) {
            PipelineTask::query()->create(['task_id' => 'task-'.$status, 'status' => $status]);
            $job = PipelineJob::query()->create(['job_id' => 'job-'.$status, 'status' => $status]);
            DB::table('pipeline_stage_states')->insert(['pipeline_job_id' => $job->id, 'job_id' => $job->job_id, 'stage' => 'ingest', 'status' => $status]);
        }
        DB::table('ingestion_sources')->insert([
            ['source_id' => 'unknown', 'source_url' => 'https://example.test/a', 'index_status' => 'future', 'temporal_schedule_id' => null],
            ['source_id' => 'ready', 'source_url' => 'https://example.test/b', 'index_status' => 'ready', 'temporal_schedule_id' => 'live-schedule'],
        ]);
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $this->assertSame(0, Artisan::call('rag:legacy-drain-status', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(3, $report['counts']['nonterminal_pipeline_tasks']);
        $this->assertSame(2, $report['counts']['nonterminal_pipeline_jobs']);
        $this->assertSame(2, $report['counts']['nonterminal_stage_states']);
        $this->assertSame(1, $report['counts']['active_ingestion_sources']);
        $this->assertSame(1, $report['counts']['scheduled_ingestion_sources']);
        $this->assertFalse($report['safe_to_drop']);
        $this->assertFalse($report['legacy_work_drained']);
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|drop|alter|create|truncate)\b/i', $sql);
        }
        $health = app(PipelineOwnershipRepository::class)->drainCounts();
        $this->assertSame(3, $health['active_legacy_task_count']);
        $this->assertSame(2, $health['active_legacy_job_count']);
        $this->assertFalse($health['legacy_drain_complete']);
    }

    public function test_empty_schema_still_requires_retention_and_drop_approval(): void
    {
        $report = app(LegacyDrainStatusService::class)->report();
        $this->assertTrue($report['schema_complete']);
        $this->assertTrue($report['legacy_work_drained']);
        $this->assertFalse($report['safe_to_drop']);
        foreach (['grants', 'grant_tokens', 'scrape_jobs', 'scrape_statistics', 'scraped_elements',
            'ingested_pages', 'rag_ingestion_artifacts', 'rag_graph_failures', 'active_managed_outputs',
            'open_managed_document_deletions', 'residual_legacy_datasets'] as $key) {
            $this->assertArrayHasKey($key, $report['counts']);
        }
    }

    public function test_retained_history_expired_tokens_outputs_and_failed_deletions_are_measured(): void
    {
        $datasetCount = DB::table('datasets')->count();
        DB::table('datasets')->insert(['dataset_id' => 'retained', 'name' => 'Retained', 'status' => 'archived', 'qdrant_collection' => 'legacy-vector', 'neo4j_namespace' => 'legacy-graph']);
        DB::table('dataset_grants')->insert(['dataset_id' => 'retained', 'principal_type' => 'user', 'principal_id' => '1', 'permission' => 'query']);
        DB::table('dataset_grant_tokens')->insert(['dataset_id' => 'retained', 'token_hash' => str_repeat('a', 64), 'expires_at' => '2020-01-01 00:00:00']);
        DB::table('managed_documents')->insert(['document_id' => 'legacy-document', 'dataset_id' => 'retained']);
        foreach (['pending', 'failed', 'unknown', 'completed', 'continued', 'abandoned'] as $status) {
            DB::table('managed_document_deletions')->insert(['operation_id' => $status, 'document_id' => 'legacy-document',
                'dataset_id' => 'retained', 'purpose' => 'delete', 'status' => $status, 'workflow_id' => $status, 'workflow_input' => '{}']);
        }
        foreach ([true, false] as $active) {
            DB::table('managed_document_outputs')->insert(['document_id' => 'legacy-document', 'bridge_document_id' => $active ? 'active' : 'inactive',
                'qdrant_collection' => 'legacy-vector', 'active' => $active]);
        }
        DB::table('scrape_jobs')->insert(['job_id' => 'legacy-scrape', 'url' => 'https://example.test', 'label' => 'History']);
        DB::table('scrape_statistics')->insert(['job_id' => 'legacy-scrape']);
        DB::table('scraped_elements')->insert(['uuid' => 'retained-element', 'page_url' => 'https://example.test', 'job_id' => 'legacy-scrape', 'deleted_at' => '2020-01-01 00:00:00']);
        DB::table('ingested_pages')->insert(['collection' => 'legacy-vector', 'source_identity_hash' => str_repeat('b', 64),
            'source_identity' => 'https://example.test', 'doc_id' => 'old-page', 'content_hash' => str_repeat('c', 64)]);
        $job = PipelineJob::query()->create(['job_id' => 'finished', 'status' => 'completed']);
        $eventId = DB::table('pipeline_worker_events')->insertGetId(['pipeline_job_id' => $job->id, 'event_id' => 'event',
            'job_id' => 'finished', 'source_id' => 'source', 'workflow_id' => 'workflow', 'run_id' => 'run', 'activity_id' => 'activity',
            'attempt' => 1, 'event_type' => 'index', 'producer' => 'indexer', 'stage' => 'ingest', 'phase' => 'finished',
            'status' => 'completed', 'payload_hash' => str_repeat('d', 64), 'payload' => '{}', 'occurred_at' => '2020-01-01 00:00:00']);
        $artifactId = DB::table('rag_ingestion_artifacts')->insertGetId(['pipeline_job_id' => $job->id, 'pipeline_worker_event_id' => $eventId,
            'job_id' => 'finished', 'source_id' => 'source', 'dataset_id' => 'retained', 'workflow_id' => 'workflow', 'run_id' => 'run',
            'summary' => '{}', 'occurred_at' => '2020-01-01 00:00:00']);
        DB::table('rag_graph_failures')->insert(['rag_ingestion_artifact_id' => $artifactId, 'job_id' => 'finished', 'source_id' => 'source',
            'dataset_id' => 'retained', 'error_code' => 'old-failure', 'message' => 'Retained evidence', 'context' => '{}', 'occurred_at' => '2020-01-01 00:00:00']);

        $report = app(LegacyDrainStatusService::class)->report();
        foreach (['grants', 'grant_tokens', 'active_managed_outputs', 'scrape_jobs', 'scrape_statistics', 'scraped_elements',
            'ingested_pages', 'rag_ingestion_artifacts', 'rag_graph_failures', 'pipeline_worker_events'] as $key) {
            $this->assertSame(1, $report['counts'][$key], $key);
        }
        $this->assertSame($datasetCount + 1, $report['counts']['residual_legacy_datasets']);
        $this->assertSame(3, $report['counts']['open_managed_document_deletions']);
        $this->assertFalse($report['safe_to_drop']);
    }

    public function test_each_python_selector_independently_makes_settings_deployment_managed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'epoch15a-settings-');
        file_put_contents($path, '{}');
        config()->set('config.admin_settings_path', $path);
        try {
            foreach (self::SELECTORS as $key) {
                config()->set('config.'.$key, 'python');
                $this->getJson('/api/settings/config')->assertOk()->assertJsonPath('effective', false)
                    ->assertJsonPath('managedBy', 'deployment');
                $this->putJson('/api/settings/config', [])->assertStatus(409);
                $this->assertSame('{}', file_get_contents($path));
                config()->set('config.'.$key, 'laravel');
            }
        } finally {
            unlink($path);
        }
    }

    public function test_missing_schema_is_unknown_and_never_a_drop_signal(): void
    {
        Schema::drop('rag_graph_failures');
        $this->assertSame(1, Artisan::call('rag:legacy-drain-status', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNull($report['counts']['rag_graph_failures']);
        $this->assertFalse($report['schema_complete']);
        $this->assertFalse($report['safe_to_drop']);
    }

    public function test_python_shared_upload_reads_do_not_need_legacy_tables(): void
    {
        $fixture = json_decode(File::get(base_path('tests/Fixtures/python_managed_upload.fixture')), true, flags: JSON_THROW_ON_ERROR);
        $root = sys_get_temp_dir().'/epoch15a-uploads-'.bin2hex(random_bytes(6));
        $path = $root.'/'.$fixture['relative_path'];
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '%PDF python upload');
        $bff = new QueryBffFixture;
        config()->set(['config.managed_document_backend' => 'python', 'config.shared_root' => $root,
            'config.python_managed_document_url' => 'http://python.test', 'config.query_bff' => $bff->config->get('config.query_bff')]);
        $document = ['document_id' => 'adoc_python', 'source_type' => 'upload', 'status' => 'indexed', 'latest_task_id' => $fixture['task_id'], 'latest_source_id' => 'source_'.str_repeat('a', 32),
            'upload' => ['task_id' => $fixture['task_id'], 'source_id' => 'source_'.str_repeat('a', 32), 'relative_path' => $fixture['relative_path'], 'filename' => $fixture['filename'], 'sha256' => hash_file('sha256', $path)]];
        Http::preventStrayRequests();
        Http::fake(['http://python.test/v1/documents/adoc_python*' => Http::response(['success' => true, 'document' => $document])]);
        $this->actingAsApiUser();
        Schema::disableForeignKeyConstraints();
        Schema::drop('pipeline_jobs');
        Schema::drop('ingestion_sources');
        try {
            $response = $this->get('/api/documents/uploads/download?'.http_build_query(['document_id' => 'adoc_python', 'source_url' => 'upload://'.$fixture['filename'], 'content_hash' => hash_file('sha256', $path)]))->assertOk();
            $this->assertSame(realpath($path), $response->baseResponse->getFile()->getRealPath());
            $this->getJson('/api/documents/uploads/download?'.http_build_query(['document_id' => 'adoc_python', 'source_url' => 'upload://absent.pdf']))->assertNotFound();
            $this->getJson('/api/documents/uploads/download?'.http_build_query(['source_url' => 'upload://'.$fixture['filename']]))->assertNotFound();
        } finally {
            File::deleteDirectory($root);
            $bff->close();
            Schema::enableForeignKeyConstraints();
        }
    }
}
