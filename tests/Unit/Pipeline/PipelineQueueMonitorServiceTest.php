<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Queues\PipelineQueueHealthPayloadService;
use App\Services\Pipeline\Queues\PipelineQueueMonitorClient;
use App\Services\Pipeline\Queues\PipelineQueueMonitorService;
use App\Services\Pipeline\Queues\PipelineQueueTopologyService;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Clock\MockClock;
use Tests\TestCase;

class PipelineQueueMonitorServiceTest extends TestCase
{
    public function test_it_reports_management_failures_with_an_injected_clock_timestamp(): void
    {
        config()->set('communication.rabbitmq.management_url', 'http://rabbit.test');
        config()->set('communication.rabbitmq.user', 'guest');
        config()->set('communication.rabbitmq.password', 'guest');
        config()->set('communication.rabbitmq.vhost', '/');
        Http::fake([
            'http://rabbit.test/api/queues/%2F' => Http::response(['error' => 'blocked'], 503),
        ]);

        $service = new PipelineQueueMonitorService(
            app(PipelineQueueMonitorClient::class),
            app(PipelineQueueTopologyService::class),
            app(PipelineQueueHealthPayloadService::class),
            new MockClock('2026-06-09T12:00:00+00:00'),
        );

        $status = $service->status(5);

        $this->assertSame('fail', $status['status']);
        $this->assertSame('2026-06-09T12:00:00+00:00', $status['checkedAt']);
        $this->assertStringStartsWith('HTTP 503 from http://rabbit.test/api/queues.', $status['error']);
    }
}
