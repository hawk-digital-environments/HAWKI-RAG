<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PipelineQueueMonitorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('communication.rabbitmq.management_url', 'http://rabbit.test');
        config()->set('communication.rabbitmq.user', 'guest');
        config()->set('communication.rabbitmq.password', 'guest');
        config()->set('communication.rabbitmq.vhost', '/');
    }

    public function test_pipeline_health_page_and_queue_api_show_healthy_queues(): void
    {
        $this->withoutVite();
        Http::fake([
            'http://rabbit.test/api/queues/%2F' => Http::response($this->queuePayload([
                ['pipeline_scraper_events', 0, 0, 1],
                ['pipeline_scraper_events.retry.scrape_requested', 0, 0, 0],
                ['pipeline_scrape_monitor_events', 0, 0, 1],
                ['pipeline_scrape_monitor_events.retry.scrape_monitor_requested', 0, 0, 0],
                ['pipeline_converter_events', 0, 0, 1],
                ['pipeline_converter_events.retry.file_discovered', 0, 0, 0],
                ['pipeline_ingestion_events', 0, 0, 1],
                ['pipeline_ingestion_events.retry.page_scraped', 0, 0, 0],
                ['pipeline_ingestion_events.retry.file_converted', 0, 0, 0],
                ['pipeline_failed_events', 0, 0, 0],
            ]), 200),
        ]);

        $this->get('/pipeline-health')
            ->assertOk()
            ->assertSee('RabbitMQ Queue Monitor');

        $this->getJson('/api/pipeline/health/queues')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('queueMonitor.status', 'ok')
            ->assertJsonPath('queueMonitor.workers.0.queueName', 'pipeline_scraper_events')
            ->assertJsonPath('queueMonitor.workers.0.readyMessages', 0)
            ->assertJsonPath('queueMonitor.workers.0.consumers', 1)
            ->assertJsonPath('queueMonitor.totals.consumers', 4)
            ->assertJsonPath('queueMonitor.totals.failedQueueCount', 0);
    }

    public function test_queue_api_warns_when_pipeline_queues_are_stuck(): void
    {
        Http::fake([
            'http://rabbit.test/api/queues/%2F' => Http::response($this->queuePayload([
                ['pipeline_scraper_events', 4, 0, 0],
                ['pipeline_scraper_events.retry.scrape_requested', 2, 0, 0],
                ['pipeline_scrape_monitor_events', 0, 0, 1],
                ['pipeline_scrape_monitor_events.retry.scrape_monitor_requested', 0, 0, 0],
                ['pipeline_converter_events', 0, 1, 1],
                ['pipeline_converter_events.retry.file_discovered', 0, 0, 0],
                ['pipeline_ingestion_events', 0, 0, 1],
                ['pipeline_ingestion_events.retry.page_scraped', 0, 0, 0],
                ['pipeline_ingestion_events.retry.file_converted', 1, 0, 0],
                ['pipeline_failed_events', 3, 0, 0],
            ]), 200),
        ]);

        $this->getJson('/api/pipeline/health/queues')
            ->assertOk()
            ->assertJsonPath('queueMonitor.status', 'warn')
            ->assertJsonPath('queueMonitor.workers.0.status', 'warn')
            ->assertJsonPath('queueMonitor.workers.0.readyMessages', 4)
            ->assertJsonPath('queueMonitor.workers.0.consumers', 0)
            ->assertJsonPath('queueMonitor.workers.0.retryQueueCount', 2)
            ->assertJsonPath('queueMonitor.totals.retryQueueCount', 3)
            ->assertJsonPath('queueMonitor.totals.failedQueueCount', 3)
            ->assertJsonFragment([
                'scraper: Messages are ready but no consumers are attached.',
            ])
            ->assertJsonFragment([
                'Failed queue contains 3 messages.',
            ]);
    }

    public function test_queue_api_reports_management_api_failure_with_fix(): void
    {
        Http::fake([
            'http://rabbit.test/api/queues/%2F' => Http::response(['error' => 'blocked'], 503),
        ]);

        $this->getJson('/api/pipeline/health/queues')
            ->assertOk()
            ->assertJsonPath('queueMonitor.status', 'fail')
            ->assertJsonPath('queueMonitor.message', 'RabbitMQ management API is not reachable.')
            ->assertJsonPath('queueMonitor.workers.0.status', 'fail')
            ->assertJsonFragment([
                'fix' => 'Start rabbitmq management and verify RABBITMQ_MANAGEMENT_URL, RABBITMQ_USER, RABBITMQ_PASSWORD, and RABBITMQ_VHOST.',
            ]);
    }

    private function queuePayload(array $rows): array
    {
        return array_map(fn (array $row): array => [
            'name' => $row[0],
            'messages_ready' => $row[1],
            'messages_unacknowledged' => $row[2],
            'messages' => $row[1] + $row[2],
            'consumers' => $row[3],
            'state' => 'running',
        ], $rows);
    }
}
