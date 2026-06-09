<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventTopologyService;
use App\Services\Pipeline\Exceptions\PipelineEventException;
use App\Services\Rag\RagRabbitMQ;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use Tests\TestCase;

class PipelineEventTopologyServiceTest extends TestCase
{
    public function test_it_declares_worker_retry_and_failed_topology(): void
    {
        config()->set('communication.rabbitmq.pipeline_events.exchange', 'pipeline.events');
        config()->set('communication.rabbitmq.pipeline_events.retry_exchange', 'pipeline.retry');
        config()->set('communication.rabbitmq.pipeline_events.failed_exchange', 'pipeline.failures');
        config()->set('communication.rabbitmq.pipeline_events.failed_queue', 'pipeline_failed_events');
        config()->set('communication.rabbitmq.pipeline_events.failed_routing_key', PipelineEvent::JOB_FAILED);
        config()->set('communication.rabbitmq.pipeline_events.workers.scrape_monitor', [
            'queue' => 'pipeline_scrape_monitor_events',
            'consumer_tag' => 'hawki-rag-scrape-monitor-events',
            'listen' => [PipelineEvent::SCRAPE_MONITOR_REQUESTED],
        ]);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('exchange_declare')->zeroOrMoreTimes();
        $channel->shouldReceive('queue_declare')
            ->once()
            ->with('pipeline_scrape_monitor_events', false, true, false, false, false, Mockery::any());
        $channel->shouldReceive('queue_bind')
            ->once()
            ->with('pipeline_scrape_monitor_events', 'pipeline.events', PipelineEvent::SCRAPE_MONITOR_REQUESTED);
        $channel->shouldReceive('queue_declare')
            ->once()
            ->with('pipeline_scrape_monitor_events.retry.scrape_monitor_requested', false, true, false, false, false, Mockery::any());
        $channel->shouldReceive('queue_bind')
            ->once()
            ->with('pipeline_scrape_monitor_events.retry.scrape_monitor_requested', 'pipeline.retry', PipelineEvent::SCRAPE_MONITOR_REQUESTED);
        $channel->shouldReceive('queue_declare')
            ->once()
            ->with('pipeline_failed_events', false, true, false, false, false, Mockery::any());
        $channel->shouldReceive('queue_bind')
            ->once()
            ->with('pipeline_failed_events', 'pipeline.failures', PipelineEvent::JOB_FAILED);

        $rabbit = Mockery::mock(RagRabbitMQ::class);
        $rabbit->shouldReceive('channel')->zeroOrMoreTimes()->andReturn($channel);
        $this->app->instance(RagRabbitMQ::class, $rabbit);

        $topology = app(PipelineEventTopologyService::class)->declareWorker('scrape_monitor');

        $this->assertSame('pipeline_scrape_monitor_events', $topology['queue']);
        $this->assertSame('hawki-rag-scrape-monitor-events', $topology['consumer_tag']);
        $this->assertSame([PipelineEvent::SCRAPE_MONITOR_REQUESTED], $topology['listen']);
    }

    public function test_it_rejects_unknown_workers_with_a_pipeline_exception(): void
    {
        config()->set('communication.rabbitmq.pipeline_events.workers', []);

        $this->expectException(PipelineEventException::class);
        $this->expectExceptionMessage('Unknown pipeline event worker [missing_worker].');

        app(PipelineEventTopologyService::class)->declareWorker('missing_worker');
    }
}
