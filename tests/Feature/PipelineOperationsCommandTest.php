<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Pipeline\Events\PipelineEventBus;
use App\Services\Rag\RagRabbitMQ;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use Tests\TestCase;

class PipelineOperationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_health_reports_all_dependencies_as_ok(): void
    {
        $this->configureHealthyPipeline();

        $rabbit = Mockery::mock(RagRabbitMQ::class);
        $rabbit->shouldReceive('channel')->once()->andReturn(Mockery::mock(AMQPChannel::class));
        $rabbit->shouldReceive('close')->once();
        $this->app->instance(RagRabbitMQ::class, $rabbit);

        $exitCode = Artisan::call('pipeline:health', ['--timeout' => 1]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('[OK] Database', $output);
        $this->assertStringContainsString('[OK] RabbitMQ', $output);
        $this->assertStringContainsString('[OK] Scraper worker', $output);
        $this->assertStringContainsString('[OK] Scrape monitor worker', $output);
        $this->assertStringContainsString('[OK] Converter worker', $output);
        $this->assertStringContainsString('[OK] Ingestion worker', $output);
        $this->assertStringContainsString('[OK] Qdrant', $output);
        $this->assertStringContainsString('[OK] Neo4j', $output);
        $this->assertStringContainsString('[OK] Shared storage', $output);
        $this->assertStringContainsString('Pipeline health passed.', $output);
    }

    public function test_pipeline_health_reports_clear_failure_suggestions(): void
    {
        $this->configureHealthyPipeline();

        $rabbit = Mockery::mock(RagRabbitMQ::class);
        $rabbit->shouldReceive('channel')->once()->andThrow(new \RuntimeException('rabbit down'));
        $rabbit->shouldReceive('close')->never();
        $this->app->instance(RagRabbitMQ::class, $rabbit);

        $exitCode = Artisan::call('pipeline:health', ['--timeout' => 1]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL] RabbitMQ', $output);
        $this->assertStringContainsString('rabbit down', $output);
        $this->assertStringContainsString('Fix: Start rabbitmq and verify RABBITMQ_HOST', $output);
        $this->assertStringContainsString('Pipeline health failed.', $output);
    }

    public function test_pipeline_health_reports_the_active_database_connection_config(): void
    {
        $this->configureHealthyPipeline();
        $this->useAlternateDatabaseConnectionName('pipeline_health_test');

        config()->set('database.connections.mysql.host', 'hardcoded-mysql-host');
        config()->set('database.connections.mysql.port', '9999');
        config()->set('database.connections.mysql.database', 'hardcoded_mysql_database');

        $rabbit = Mockery::mock(RagRabbitMQ::class);
        $rabbit->shouldReceive('channel')->once()->andReturn(Mockery::mock(AMQPChannel::class));
        $rabbit->shouldReceive('close')->once();
        $this->app->instance(RagRabbitMQ::class, $rabbit);

        $exitCode = Artisan::call('pipeline:health', ['--timeout' => 1]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Connected via pipeline_health_test', $output);
        $this->assertStringNotContainsString('hardcoded-mysql-host', $output);
        $this->assertStringNotContainsString('hardcoded_mysql_database', $output);
    }

    public function test_pipeline_workers_prints_start_commands_and_queue_names(): void
    {
        $exitCode = Artisan::call('pipeline:workers');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('docker compose --profile pipeline-events up -d', $output);
        $this->assertStringContainsString('php artisan pipeline:declare-event-topology', $output);
        $this->assertStringContainsString('php artisan pipeline:scraper-event-worker', $output);
        $this->assertStringContainsString('php artisan pipeline:scrape-monitor-event-worker', $output);
        $this->assertStringContainsString('php artisan pipeline:converter-event-worker', $output);
        $this->assertStringContainsString('php artisan pipeline:ingestion-event-worker', $output);
        $this->assertStringNotContainsString('php artisan queue:work database --queue=default', $output);
        $this->assertStringContainsString('RabbitMQ owns Crawl4AI status polling', $output);
        $this->assertStringContainsString('pipeline_scraper_events', $output);
        $this->assertStringContainsString('pipeline_scrape_monitor_events', $output);
        $this->assertStringContainsString('pipeline_scraper_events.retry.scrape_requested', $output);
        $this->assertStringContainsString('pipeline_failed_events', $output);
        $this->assertStringContainsString('No Prefect. No Redis.', $output);
    }

    public function test_pipeline_declare_event_topology_declares_requested_worker(): void
    {
        $bus = Mockery::mock(PipelineEventBus::class);
        $bus->shouldReceive('declareWorkerTopology')
            ->once()
            ->with('scrape_monitor')
            ->andReturn([
                'queue' => 'pipeline_scrape_monitor_events',
                'listen' => ['scrape.monitor.requested'],
            ]);
        $bus->shouldReceive('declareFailedTopology')
            ->once()
            ->andReturn([
                'queue' => 'pipeline_failed_events',
                'routing_key' => 'job.failed',
            ]);
        $this->app->instance(PipelineEventBus::class, $bus);

        $exitCode = Artisan::call('pipeline:declare-event-topology', ['worker' => 'scrape_monitor']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Declared scrape_monitor: queue pipeline_scrape_monitor_events listens to scrape.monitor.requested.', $output);
        $this->assertStringContainsString('Declared failed queue pipeline_failed_events for job.failed.', $output);
    }

    private function configureHealthyPipeline(): void
    {
        $sharedPath = storage_path('framework/testing/pipeline-shared');
        File::ensureDirectoryExists($sharedPath);

        config()->set('communication.rabbitmq.pipeline_events.enabled', true);
        config()->set('communication.rabbitmq.pipeline_ingestion.shared_storage_root', $sharedPath);
        config()->set('communication.rabbitmq.pipeline_ingestion.shared_storage_web_user', '');
        config()->set('scraper.storage_path', $sharedPath);
        config()->set('config.shared_root', $sharedPath);
        config()->set('scraper.api_url', 'http://crawler.test');
        config()->set('file_converter.url', 'http://converter.test/extract');
        config()->set('file_converter.health_url', 'http://converter.test/health');
        config()->set('config.hawki_rag_bridge_url', 'http://bridge.test');
        config()->set('config.qdrant_http_url', 'http://qdrant.test');
        config()->set('config.neo4j_http_url', 'http://neo4j.test');
        config()->set('config.neo4j_user', 'neo4j');
        config()->set('config.neo4j_password', 'secret');

        Http::preventStrayRequests();
        Http::fake([
            'http://crawler.test/health' => Http::response(['ok' => true], 200),
            'http://converter.test/health' => Http::response(['status' => 'OK'], 200),
            'http://bridge.test/health' => Http::response(['ok' => true], 200),
            'http://qdrant.test/collections' => Http::response(['result' => ['collections' => []]], 200),
            'http://neo4j.test/db/neo4j/tx/commit' => Http::response(['results' => [], 'errors' => []], 200),
        ]);
    }

    private function useAlternateDatabaseConnectionName(string $connectionName): void
    {
        $defaultConnection = (string) config('database.default');
        $defaultConfig = config("database.connections.{$defaultConnection}");

        config()->set("database.connections.{$connectionName}", $defaultConfig);
        config()->set('database.default', $connectionName);
        DB::purge($connectionName);
    }
}
