<?php

declare(strict_types=1);

namespace App\Services\Rag;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class RagRabbitMQ
{
    private ?AMQPStreamConnection $connection = null;
    private ?AMQPChannel $channel = null;

    public function __construct(private readonly RagRabbitMQConfig $config)
    {
    }

    public function channel(): AMQPChannel
    {
        if ($this->channel instanceof AMQPChannel) {
            return $this->channel;
        }

        $this->channel = $this->connection()->channel();

        return $this->channel;
    }

    public function publisherChannel(): AMQPChannel
    {
        return $this->connection()->channel();
    }

    private function connection(): AMQPStreamConnection
    {
        if ($this->connection instanceof AMQPStreamConnection) {
            return $this->connection;
        }

        $this->connection = new AMQPStreamConnection(
            $this->config->host(),
            $this->config->port(),
            $this->config->user(),
            $this->config->password(),
            $this->config->vhost(),
            false,
            'AMQPLAIN',
            null,
            'en_US',
            $this->config->connectionTimeout(),
            $this->config->readWriteTimeout(),
            null,
            false,
            $this->config->heartbeat(),
        );

        return $this->connection;
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
