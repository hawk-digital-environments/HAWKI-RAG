<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Events;

use App\Services\Pipeline\Exceptions\PipelineEventException;
use App\Services\Pipeline\Queues\PipelineQueueTopologyService;
use App\Services\Rag\RagRabbitMQ;
use Illuminate\Container\Attributes\Singleton;
use PhpAmqpLib\Wire\AMQPTable;

#[Singleton]
readonly class PipelineEventTopologyService
{
    public function __construct(
        private RagRabbitMQ $rabbit,
        private PipelineQueueTopologyService $queues,
        private PipelineEventConfig $config,
    ) {}

    /**
     * @return array{queue: string, consumer_tag: string, listen: list<string>}
     */
    public function declareWorker(string $worker): array
    {
        $cfg = $this->config->worker($worker);
        if ($cfg === null) {
            throw PipelineEventException::unknownWorker($worker);
        }

        $this->declareExchanges();
        $channel = $this->rabbit->channel();
        $queue = (string) $cfg['queue'];
        $events = array_values(array_filter(array_map('strval', $cfg['listen'] ?? [])));
        $queueArgs = $this->queueArguments();

        $channel->queue_declare($queue, false, true, false, false, false, $queueArgs);
        foreach ($events as $eventType) {
            $channel->queue_bind($queue, $this->config->exchange(), $eventType);
            $this->declareRetryQueue($queue, $eventType);
        }

        $this->declareFailed();

        return [
            'queue' => $queue,
            'consumer_tag' => (string) ($cfg['consumer_tag'] ?? "hawki-rag-{$worker}-events"),
            'listen' => $events,
        ];
    }

    /**
     * @return array{queue: string, routing_key: string}
     */
    public function declareFailed(): array
    {
        $this->declareExchanges();
        $channel = $this->rabbit->channel();
        $queueArgs = $this->queueArguments();
        $failedQueue = $this->config->failedQueue();
        $channel->queue_declare($failedQueue, false, true, false, false, false, $queueArgs);
        $channel->queue_bind(
            $failedQueue,
            $this->config->failedExchange(),
            $this->config->failedRoutingKey(),
        );

        return [
            'queue' => $failedQueue,
            'routing_key' => $this->config->failedRoutingKey(),
        ];
    }

    public function declareForEvent(string $eventType): void
    {
        $this->declareExchanges();

        foreach ($this->config->workers() as $worker => $cfg) {
            if (! is_array($cfg)) {
                continue;
            }

            $events = array_values(array_filter(array_map('strval', $cfg['listen'] ?? [])));
            if (in_array($eventType, $events, true)) {
                $this->declareWorker((string) $worker);
            }
        }
    }

    private function declareExchanges(): void
    {
        $channel = $this->rabbit->channel();

        $channel->exchange_declare($this->config->exchange(), $this->config->exchangeType(), false, true, false);
        $channel->exchange_declare($this->config->retryExchange(), $this->config->retryExchangeType(), false, true, false);
        $channel->exchange_declare($this->config->failedExchange(), $this->config->failedExchangeType(), false, true, false);
    }

    private function declareRetryQueue(string $workerQueue, string $eventType): void
    {
        $arguments = [
            'x-message-ttl' => $this->config->retryDelayMs(),
            'x-dead-letter-exchange' => $this->config->exchange(),
            'x-dead-letter-routing-key' => $eventType,
        ];

        if ($this->config->usesQuorumQueues()) {
            $arguments['x-queue-type'] = 'quorum';
        }

        $retryQueue = $this->queues->retryQueueName($workerQueue, $eventType);
        $channel = $this->rabbit->channel();
        $channel->queue_declare($retryQueue, false, true, false, false, false, new AMQPTable($arguments));
        $channel->queue_bind(
            $retryQueue,
            $this->config->retryExchange(),
            $this->retryRoutingKey($eventType),
        );
    }

    private function queueArguments(): ?AMQPTable
    {
        if (! $this->config->usesQuorumQueues()) {
            return null;
        }

        return new AMQPTable(['x-queue-type' => 'quorum']);
    }

    private function retryRoutingKey(string $eventType): string
    {
        return $eventType;
    }
}
