<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Events;

use App\Services\Rag\RagRabbitMQ;
use Illuminate\Container\Attributes\Singleton;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;

#[Singleton]
readonly class PipelineEventPublisher
{
    public function __construct(
        private RagRabbitMQ $rabbit,
    ) {}

    public function publish(string $exchange, string $routingKey, array $payload, bool $mandatory = true): void
    {
        $returned = false;
        $nacked = false;
        $channel = $this->rabbit->publisherChannel();

        try {
            $channel->confirm_select();
            $channel->set_return_listener(static function () use (&$returned): void {
                $returned = true;
            });
            $channel->set_nack_handler(static function () use (&$nacked): void {
                $nacked = true;
            });

            $message = new AMQPMessage(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]);

            $channel->basic_publish($message, $exchange, $routingKey, $mandatory);
            $channel->wait_for_pending_acks_returns(5.0);
        } finally {
            $channel->close();
        }

        if ($returned) {
            throw new RuntimeException("RabbitMQ returned unroutable event [{$routingKey}] on exchange [{$exchange}].");
        }

        if ($nacked) {
            throw new RuntimeException("RabbitMQ nacked event [{$routingKey}] on exchange [{$exchange}].");
        }
    }
}
