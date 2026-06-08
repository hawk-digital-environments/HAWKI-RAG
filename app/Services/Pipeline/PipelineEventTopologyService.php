<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Services\Rag\RagRabbitMQ;
use Illuminate\Container\Attributes\Singleton;
use PhpAmqpLib\Wire\AMQPTable;

#[Singleton]
readonly class PipelineEventTopologyService
{
    public function __construct(
        private RagRabbitMQ $rabbit,
        private PipelineQueueTopologyService $queues,
    ) {
    }

    /**
     * @return array{queue: string, consumer_tag: string, listen: list<string>}
     */
    public function declareWorker(string $worker): array
    {
        $cfg = config("communication.rabbitmq.pipeline_events.workers.{$worker}");
        if (!is_array($cfg)) {
            throw new \InvalidArgumentException("Unknown pipeline event worker: {$worker}");
        }

        $this->declareExchanges();
        $channel = $this->rabbit->channel();
        $queue = (string) $cfg['queue'];
        $events = array_values(array_filter(array_map('strval', $cfg['listen'] ?? [])));
        $queueArgs = $this->queueArguments();

        $channel->queue_declare($queue, false, true, false, false, false, $queueArgs);
        foreach ($events as $eventType) {
            $channel->queue_bind($queue, (string) config('communication.rabbitmq.pipeline_events.exchange'), $eventType);
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
        $failedQueue = (string) config('communication.rabbitmq.pipeline_events.failed_queue', 'pipeline_failed_events');
        $channel->queue_declare($failedQueue, false, true, false, false, false, $queueArgs);
        $channel->queue_bind(
            $failedQueue,
            (string) config('communication.rabbitmq.pipeline_events.failed_exchange'),
            (string) config('communication.rabbitmq.pipeline_events.failed_routing_key', PipelineEvent::JOB_FAILED),
        );

        return [
            'queue' => $failedQueue,
            'routing_key' => (string) config('communication.rabbitmq.pipeline_events.failed_routing_key', PipelineEvent::JOB_FAILED),
        ];
    }

    public function declareForEvent(string $eventType): void
    {
        $this->declareExchanges();

        foreach ((array) config('communication.rabbitmq.pipeline_events.workers', []) as $worker => $cfg) {
            if (!is_array($cfg)) {
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
        $cfg = config('communication.rabbitmq.pipeline_events');

        $channel->exchange_declare((string) $cfg['exchange'], (string) $cfg['exchange_type'], false, true, false);
        $channel->exchange_declare((string) $cfg['retry_exchange'], (string) $cfg['retry_exchange_type'], false, true, false);
        $channel->exchange_declare((string) $cfg['failed_exchange'], (string) $cfg['failed_exchange_type'], false, true, false);
    }

    private function declareRetryQueue(string $workerQueue, string $eventType): void
    {
        $arguments = [
            'x-message-ttl' => (int) config('communication.rabbitmq.pipeline_events.retry_delay_ms', 5000),
            'x-dead-letter-exchange' => (string) config('communication.rabbitmq.pipeline_events.exchange'),
            'x-dead-letter-routing-key' => $eventType,
        ];

        if ((string) config('communication.rabbitmq.pipeline_events.queue_type', 'quorum') === 'quorum') {
            $arguments['x-queue-type'] = 'quorum';
        }

        $retryQueue = $this->queues->retryQueueName($workerQueue, $eventType);
        $channel = $this->rabbit->channel();
        $channel->queue_declare($retryQueue, false, true, false, false, false, new AMQPTable($arguments));
        $channel->queue_bind(
            $retryQueue,
            (string) config('communication.rabbitmq.pipeline_events.retry_exchange'),
            $this->retryRoutingKey($eventType),
        );
    }

    private function queueArguments(): ?AMQPTable
    {
        if ((string) config('communication.rabbitmq.pipeline_events.queue_type', 'quorum') !== 'quorum') {
            return null;
        }

        return new AMQPTable(['x-queue-type' => 'quorum']);
    }

    private function retryRoutingKey(string $eventType): string
    {
        return $eventType;
    }
}
