<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Architecture\PipelineArchitectureService;
use App\Services\Pipeline\Events\PipelineEvent;
use Tests\TestCase;

class PipelineArchitectureServiceTest extends TestCase
{
    public function test_it_describes_event_contracts_from_configured_workers(): void
    {
        config()->set('communication.rabbitmq.pipeline_events.workers', [
            'scraper' => [
                'queue' => 'pipeline_scraper_events',
                'listen' => [PipelineEvent::SCRAPE_REQUESTED],
            ],
            'ingestion' => [
                'queue' => 'pipeline_ingestion_events',
                'listen' => [PipelineEvent::PAGE_SCRAPED, PipelineEvent::FILE_CONVERTED],
            ],
        ]);

        $events = app(PipelineArchitectureService::class)->events();
        $byType = collect($events)->keyBy('eventType');

        $this->assertArrayHasKey(PipelineEvent::SCRAPE_REQUESTED, $byType);
        $this->assertSame(['scraper'], $byType[PipelineEvent::SCRAPE_REQUESTED]['consumedBy']);
        $this->assertSame(['ingestion'], $byType[PipelineEvent::PAGE_SCRAPED]['consumedBy']);
        $this->assertSame(PipelineEvent::REQUIRED_PAYLOAD_FIELDS, $byType[PipelineEvent::FILE_CONVERTED]['requiredFields']);
        $this->assertContains(PipelineEvent::CONTENT_INGESTED, $byType[PipelineEvent::FILE_CONVERTED]['typicalNextEvents']);
    }

    public function test_it_summarizes_topology_flow_and_failure_modes(): void
    {
        config()->set('communication.rabbitmq.pipeline_events.exchange', 'pipeline.events');
        config()->set('communication.rabbitmq.pipeline_events.retry_exchange', 'pipeline.retry');
        config()->set('communication.rabbitmq.pipeline_events.failed_exchange', 'pipeline.failures');
        config()->set('communication.rabbitmq.pipeline_events.workers.scraper', [
            'queue' => 'pipeline_scraper_events',
            'listen' => [PipelineEvent::SCRAPE_REQUESTED],
        ]);

        $summary = app(PipelineArchitectureService::class)->summary();

        $this->assertSame('pipeline.events', $summary['topology']['eventsExchange']);
        $this->assertSame('pipeline.retry', $summary['topology']['retryExchange']);
        $this->assertSame(PipelineEvent::JOB_FAILED, $summary['topology']['failedRoutingKey']);
        $this->assertContains([PipelineEvent::SCRAPE_REQUESTED, PipelineEvent::SCRAPE_MONITOR_REQUESTED], $summary['flow']);
        $this->assertContains('retry_limit_exhausted', array_column($summary['failureModes'], 'mode'));
        $this->assertContains('ScrapeMonitorEventHandler', array_column($summary['handlers'], 'handler'));
        $this->assertContains('pipeline_jobs', array_column($summary['persistence'], 'table'));
        $this->assertContains('recovery', array_column($summary['idempotency'], 'area'));
        $this->assertContains('php artisan pipeline:architecture', $summary['health']['commands']);
        $this->assertSame('PipelineRecoveryService', $summary['recovery']['services'][0]);
        $this->assertSame('unit', $summary['testing'][0]['layer']);
        $this->assertStringContainsString('RabbitMQ event', $summary['mentalModel'][2]);
    }
}
