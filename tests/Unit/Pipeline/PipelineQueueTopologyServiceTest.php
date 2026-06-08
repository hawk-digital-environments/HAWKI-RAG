<?php
declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\PipelineQueueTopologyService;
use Tests\TestCase;

class PipelineQueueTopologyServiceTest extends TestCase
{
    public function test_it_builds_expected_workers_and_retry_queues_from_config(): void
    {
        config()->set('communication.rabbitmq.pipeline_events.failed_queue', 'pipeline_failed_events');
        config()->set('communication.rabbitmq.pipeline_events.workers', [
            'scraper' => [
                'queue' => 'pipeline_scraper_events',
                'listen' => ['scrape.requested'],
            ],
            'ingestion' => [
                'queue' => 'pipeline_ingestion_events',
                'listen' => ['page.scraped', 'file.converted'],
            ],
            'invalid' => [
                'listen' => ['ignored'],
            ],
        ]);

        $expected = app(PipelineQueueTopologyService::class)->expectedQueues();

        $this->assertSame('pipeline_failed_events', $expected['failedQueue']);
        $this->assertCount(2, $expected['workers']);
        $this->assertSame('scraper', $expected['workers'][0]['worker']);
        $this->assertSame('pipeline_scraper_events', $expected['workers'][0]['queueName']);
        $this->assertSame(['pipeline_scraper_events.retry.scrape_requested'], $expected['workers'][0]['retryQueues']);
        $this->assertSame([
            'pipeline_ingestion_events.retry.page_scraped',
            'pipeline_ingestion_events.retry.file_converted',
        ], $expected['workers'][1]['retryQueues']);
    }

    public function test_it_builds_retry_queue_names(): void
    {
        $this->assertSame(
            'worker.retry.page_scraped',
            app(PipelineQueueTopologyService::class)->retryQueueName('worker', 'page.scraped'),
        );
        $this->assertSame(
            'worker.retry.event_name',
            app(PipelineQueueTopologyService::class)->retryQueueName('worker', 'event:name'),
        );
    }
}
