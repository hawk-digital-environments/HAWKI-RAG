<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Events\PipelineEventPublisher;
use App\Services\Rag\RagRabbitMQ;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Tests\TestCase;

class PipelineEventPublisherTest extends TestCase
{
    public function test_it_publishes_a_persistent_json_message(): void
    {
        $payload = [
            'event_type' => 'page.scraped',
            'source_url' => 'https://example.test/page',
        ];

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_publish')
            ->once()
            ->with(
                Mockery::on(function (AMQPMessage $message) use ($payload): bool {
                    $this->assertSame(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $message->getBody());
                    $this->assertSame('application/json', $message->get('content_type'));
                    $this->assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $message->get('delivery_mode'));

                    return true;
                }),
                'pipeline.events',
                'page.scraped',
            );

        $rabbit = Mockery::mock(RagRabbitMQ::class);
        $rabbit->shouldReceive('channel')->once()->andReturn($channel);
        $this->app->instance(RagRabbitMQ::class, $rabbit);

        app(PipelineEventPublisher::class)->publish('pipeline.events', 'page.scraped', $payload);
    }
}
