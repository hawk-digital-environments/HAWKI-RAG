<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Queues\PipelineQueueHealthPayloadService;
use Tests\TestCase;

class PipelineQueueHealthPayloadServiceTest extends TestCase
{
    public function test_it_builds_queue_payloads(): void
    {
        $payloads = app(PipelineQueueHealthPayloadService::class);

        $queue = $payloads->queue('pipeline_scraper_events', [
            'messages_ready' => 2,
            'messages_unacknowledged' => 1,
            'consumers' => 3,
            'state' => 'running',
        ]);

        $this->assertTrue($queue['exists']);
        $this->assertSame(2, $queue['readyMessages']);
        $this->assertSame(1, $queue['unackedMessages']);
        $this->assertSame(3, $queue['messages']);
        $this->assertSame(3, $queue['consumers']);
        $this->assertSame('running', $queue['state']);

        $missing = $payloads->queue('missing_queue', null);
        $this->assertFalse($missing['exists']);
        $this->assertSame('missing', $missing['state']);
    }

    public function test_it_builds_worker_payloads_with_warnings_and_retry_counts(): void
    {
        $worker = [
            'worker' => 'scraper',
            'queueName' => 'pipeline_scraper_events',
            'listen' => ['scrape.requested'],
            'retryQueues' => ['pipeline_scraper_events.retry.scrape_requested'],
        ];
        $queues = [
            'pipeline_scraper_events' => [
                'name' => 'pipeline_scraper_events',
                'messages_ready' => 4,
                'messages_unacknowledged' => 0,
                'messages' => 4,
                'consumers' => 0,
                'state' => 'running',
            ],
            'pipeline_scraper_events.retry.scrape_requested' => [
                'name' => 'pipeline_scraper_events.retry.scrape_requested',
                'messages_ready' => 2,
                'messages_unacknowledged' => 1,
                'messages' => 3,
                'consumers' => 0,
                'state' => 'running',
            ],
        ];

        $payload = app(PipelineQueueHealthPayloadService::class)->worker($worker, $queues);

        $this->assertSame('warn', $payload['status']);
        $this->assertSame(4, $payload['readyMessages']);
        $this->assertSame(3, $payload['retryQueueCount']);
        $this->assertSame(2, $payload['retryReadyMessages']);
        $this->assertSame(1, $payload['retryUnackedMessages']);
        $this->assertContains('Messages are ready but no consumers are attached.', $payload['warnings']);
        $this->assertContains('Retry queues contain messages.', $payload['warnings']);
    }

    public function test_it_builds_missing_worker_payloads_warnings_and_totals(): void
    {
        $payloads = app(PipelineQueueHealthPayloadService::class);
        $expected = [
            'workers' => [[
                'worker' => 'scraper',
                'queueName' => 'pipeline_scraper_events',
                'listen' => ['scrape.requested'],
                'retryQueues' => ['pipeline_scraper_events.retry.scrape_requested'],
            ]],
            'failedQueue' => 'pipeline_failed_events',
        ];

        $workers = $payloads->missingWorkers($expected);
        $failedQueue = $payloads->queue('pipeline_failed_events', [
            'messages_ready' => 1,
            'messages_unacknowledged' => 1,
            'consumers' => 0,
        ]);
        $warnings = $payloads->warnings($workers, $failedQueue);
        $totals = $payloads->totals($workers, $failedQueue);

        $this->assertSame('fail', $workers[0]['status']);
        $this->assertSame('RabbitMQ management API is not reachable.', $workers[0]['warnings'][0]);
        $this->assertContains('scraper: RabbitMQ management API is not reachable.', $warnings);
        $this->assertContains('Failed queue contains 2 failed events awaiting recovery.', $warnings);
        $this->assertSame(2, $totals['failedQueueCount']);
        $this->assertSame([
            'readyMessages' => 0,
            'unackedMessages' => 0,
            'consumers' => 0,
            'retryQueueCount' => 0,
            'failedQueueCount' => 0,
        ], $payloads->emptyTotals());
    }
}
