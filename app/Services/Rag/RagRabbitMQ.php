<?php

namespace App\Services\Rag;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

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

    public function declareRagIngestionTopology(): void
    {
        $channel = $this->channel();
        $cfg = config('communication.rabbitmq.rag_ingestion');

        $this->declareExchange($channel, $cfg['events_exchange'], $cfg['events_exchange_type']);
        $this->declareExchange($channel, $cfg['retry_exchange'], $cfg['retry_exchange_type']);
        $this->declareExchange($channel, $cfg['failed_exchange'], $cfg['failed_exchange_type']);

        $mainArgs = [];
        $failedArgs = [];
        if (($cfg['queue_type'] ?? 'quorum') === 'quorum') {
            $mainArgs['x-queue-type'] = 'quorum';
            $failedArgs['x-queue-type'] = 'quorum';
        }

        $retryArgs = [
            'x-message-ttl' => (int) $cfg['retry_delay_ms'],
            'x-dead-letter-exchange' => (string) $cfg['events_exchange'],
            'x-dead-letter-routing-key' => (string) $cfg['document_converted_routing_key'],
        ];

        $this->declareQueue($channel, $cfg['queue'], $mainArgs);
        $this->declareQueue($channel, $cfg['retry_queue'], $retryArgs);
        $this->declareQueue($channel, $cfg['failed_queue'], $failedArgs);

        $channel->queue_bind($cfg['queue'], $cfg['events_exchange'], $cfg['document_converted_routing_key']);
        $channel->queue_bind($cfg['retry_queue'], $cfg['retry_exchange'], $cfg['retry_routing_key']);
        $channel->queue_bind($cfg['failed_queue'], $cfg['failed_exchange'], $cfg['failed_routing_key']);
    }

    public function publishRetry(array $payload): void
    {
        $cfg = config('communication.rabbitmq.rag_ingestion');
        $this->publish($cfg['retry_exchange'], $cfg['retry_routing_key'], $payload);
    }

    public function publishConvertedDocument(array $payload): void
    {
        $cfg = config('communication.rabbitmq.rag_ingestion');
        $this->publish($cfg['events_exchange'], $cfg['document_converted_routing_key'], $payload);
    }

    public function publishFailed(array $payload): void
    {
        $cfg = config('communication.rabbitmq.rag_ingestion');
        $this->publish($cfg['failed_exchange'], $cfg['failed_routing_key'], $payload);
    }

    public function publishPipelineEvent(string $routingKey, array $payload): void
    {
        $cfg = config('communication.rabbitmq.pipeline_events');
        $channel = $this->channel();
        $this->declareExchange($channel, (string) $cfg['exchange'], (string) $cfg['exchange_type']);
        $this->publish((string) $cfg['exchange'], $routingKey, $payload);
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

    private function declareExchange(AMQPChannel $channel, string $exchange, string $type): void
    {
        $channel->exchange_declare($exchange, $type, false, true, false);
    }

    private function declareQueue(AMQPChannel $channel, string $queue, array $arguments): void
    {
        $channel->queue_declare(
            $queue,
            false,
            true,
            false,
            false,
            false,
            $arguments === [] ? null : new AMQPTable($arguments),
        );
    }

    private function publish(string $exchange, string $routingKey, array $payload): void
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $message = new AMQPMessage($body, [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]);

        $this->channel()->basic_publish($message, $exchange, $routingKey);
    }
}
