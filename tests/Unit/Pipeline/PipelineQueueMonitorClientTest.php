<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Exceptions\PipelineQueueMonitorException;
use App\Services\Pipeline\Queues\PipelineQueueMonitorClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PipelineQueueMonitorClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('communication.rabbitmq.management_url', 'http://rabbit.test/');
        config()->set('communication.rabbitmq.user', 'guest');
        config()->set('communication.rabbitmq.password', 'guest');
        config()->set('communication.rabbitmq.vhost', '/');
    }

    public function test_it_fetches_queues_keyed_by_name(): void
    {
        Http::fake([
            'http://rabbit.test/api/queues/%2F' => Http::response([
                ['name' => 'pipeline_scraper_events', 'messages_ready' => 2],
                ['name' => 'pipeline_converter_events', 'messages_ready' => 0],
                ['not_name' => 'ignored'],
            ], 200),
        ]);

        $queues = app(PipelineQueueMonitorClient::class)->fetchQueues(5);

        $this->assertSame(2, $queues['pipeline_scraper_events']['messages_ready']);
        $this->assertSame(0, $queues['pipeline_converter_events']['messages_ready']);
        $this->assertArrayNotHasKey('ignored', $queues);
        $this->assertSame('http://rabbit.test', app(PipelineQueueMonitorClient::class)->managementUrl());
    }

    public function test_it_rejects_missing_management_url(): void
    {
        config()->set('communication.rabbitmq.management_url', '');

        $this->expectException(PipelineQueueMonitorException::class);
        $this->expectExceptionMessage('RABBITMQ_MANAGEMENT_URL is empty.');

        app(PipelineQueueMonitorClient::class)->fetchQueues(5);
    }

    public function test_it_rejects_unsuccessful_responses(): void
    {
        Http::fake([
            'http://rabbit.test/api/queues/%2F' => Http::response(['error' => 'blocked'], 503),
        ]);

        $this->expectException(PipelineQueueMonitorException::class);
        $this->expectExceptionMessage('HTTP 503 from http://rabbit.test/api/queues.');

        app(PipelineQueueMonitorClient::class)->fetchQueues(5);
    }

    public function test_it_rejects_invalid_queue_payloads(): void
    {
        Http::fake([
            'http://rabbit.test/api/queues/%2F' => Http::response('not-json', 200),
        ]);

        $this->expectException(PipelineQueueMonitorException::class);
        $this->expectExceptionMessage('RabbitMQ management API returned an invalid queue payload.');

        app(PipelineQueueMonitorClient::class)->fetchQueues(5);
    }
}
