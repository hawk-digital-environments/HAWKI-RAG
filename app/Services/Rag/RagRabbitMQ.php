<?php

namespace App\Services\Rag;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class RagRabbitMQ
{
    private ?AMQPStreamConnection $connection = null;
    private ?AMQPChannel $channel = null;

    public function channel(): AMQPChannel
    {
        if ($this->channel instanceof AMQPChannel) {
            return $this->channel;
        }

        $this->connection = new AMQPStreamConnection(
            (string) config('communication.rabbitmq.host', 'rabbitmq'),
            (int) config('communication.rabbitmq.port', 5672),
            (string) config('communication.rabbitmq.user', 'guest'),
            (string) config('communication.rabbitmq.password', 'guest'),
            (string) config('communication.rabbitmq.vhost', '/'),
            false,
            'AMQPLAIN',
            null,
            'en_US',
            (float) config('communication.rabbitmq.connection_timeout', 30),
            (float) config('communication.rabbitmq.read_write_timeout', 30),
            null,
            false,
            (int) config('communication.rabbitmq.heartbeat', 30),
        );

        $this->channel = $this->connection->channel();

        return $this->channel;
    }

    public function close(): void
    {
        if ($this->channel instanceof AMQPChannel) {
            $this->channel->close();
            $this->channel = null;
        }

        if ($this->connection instanceof AMQPStreamConnection) {
            $this->connection->close();
            $this->connection = null;
        }
    }
}
