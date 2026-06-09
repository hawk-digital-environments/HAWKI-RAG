<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Events;

use App\Services\Rag\RagRabbitMQ;
use Illuminate\Container\Attributes\Singleton;
use PhpAmqpLib\Message\AMQPMessage;

#[Singleton]
readonly class PipelineEventPublisher
{
    public function __construct(
        private RagRabbitMQ $rabbit,
    ) {}

    public function publish(string $exchange, string $routingKey, array $payload): void
    {
        $message = new AMQPMessage(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]);

        $this->rabbit->channel()->basic_publish($message, $exchange, $routingKey);
    }
}
