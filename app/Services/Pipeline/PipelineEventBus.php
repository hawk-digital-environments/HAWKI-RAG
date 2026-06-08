<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Services\Rag\RagRabbitMQ;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

#[Singleton]
class PipelineEventBus
{
    public function __construct(
        private readonly RagRabbitMQ $rabbit,
        private readonly PipelineEventRecorder $recorder,
    ) {
    }

    public function publish(string $eventType, array $payload): array
    {
        $event = PipelineEvent::normalize($eventType, $payload);
        $this->recorder->record($eventType, $event, 'rabbitmq.publish');
        $this->log('publish', $event);

        if (! (bool) config('communication.rabbitmq.pipeline_events.enabled', true)) {
            return $event;
        }

        $cfg = config('communication.rabbitmq.pipeline_events');
        $this->declareRoutableEventTopology($eventType);
        $this->publishRaw((string) $cfg['exchange'], $eventType, $event);

        return $event;
    }

    public function publishRetry(array $event, Throwable $error): ?array
    {
        if (! (bool) config('communication.rabbitmq.pipeline_events.enabled', true)) {
            return null;
        }

        $eventType = (string) ($event['event_type'] ?? '');
        if ($eventType === '') {
            return null;
        }

        $retryCount = max(0, (int) ($event['retry_count'] ?? 0)) + 1;
        $maxRetries = max(0, (int) ($event['max_retries'] ?? config('communication.rabbitmq.pipeline_events.max_retries', 3)));
        if ($retryCount > $maxRetries) {
            return null;
        }

        $retryEvent = array_merge($event, [
            'retry_count' => $retryCount,
            'max_retries' => $maxRetries,
            'last_error_type' => class_basename($error),
            'last_error_message' => $error->getMessage(),
            'metadata' => array_merge(is_array($event['metadata'] ?? null) ? $event['metadata'] : [], [
                'last_error_type' => class_basename($error),
                'last_error_message' => $error->getMessage(),
            ]),
        ]);

        $this->declareRoutableEventTopology($eventType);
        $this->publishRaw(
            (string) config('communication.rabbitmq.pipeline_events.retry_exchange'),
            $this->retryRoutingKey($eventType),
            $retryEvent,
        );
        $this->recorder->record($eventType, $retryEvent, 'rabbitmq.retry');
        $this->log('retry', $retryEvent);

        return $retryEvent;
    }

    public function publishDelayed(array $event, string $reason = 'delayed event retry'): array
    {
        $eventType = (string) ($event['event_type'] ?? '');
        if ($eventType === '') {
            throw new \InvalidArgumentException('Delayed publish requires event_type.');
        }

        $delayedEvent = PipelineEvent::normalize($eventType, array_merge($event, [
            'metadata' => array_merge(is_array($event['metadata'] ?? null) ? $event['metadata'] : [], [
                'delay_reason' => $reason,
                'delay_requested_at' => now()->toIso8601String(),
            ]),
        ]));

        $this->recorder->record($eventType, $delayedEvent, 'rabbitmq.delay', "Delayed event queued: {$eventType}");
        $this->log('delay', $delayedEvent);

        if (! (bool) config('communication.rabbitmq.pipeline_events.enabled', true)) {
            return $delayedEvent;
        }

        $this->declareRoutableEventTopology($eventType);
        $this->publishRaw(
            (string) config('communication.rabbitmq.pipeline_events.retry_exchange'),
            $this->retryRoutingKey($eventType),
            $delayedEvent,
        );

        return $delayedEvent;
    }

    public function publishRecoveryRetry(array $event, string $reason = 'operator recovery retry'): array
    {
        $eventType = (string) ($event['event_type'] ?? '');
        if ($eventType === '') {
            throw new \InvalidArgumentException('Recovery retry requires event_type.');
        }

        $retryEvent = PipelineEvent::normalize($eventType, array_merge($event, [
            'metadata' => array_merge(is_array($event['metadata'] ?? null) ? $event['metadata'] : [], [
                'recovery_reason' => $reason,
                'recovery_requested_at' => now()->toIso8601String(),
            ]),
        ]));

        $this->recorder->record($eventType, $retryEvent, 'rabbitmq.recovery', "Recovery retry queued: {$eventType}");
        $this->log('recovery_retry', $retryEvent);

        if (! (bool) config('communication.rabbitmq.pipeline_events.enabled', true)) {
            return $retryEvent;
        }

        $this->declareRoutableEventTopology($eventType);
        $this->publishRaw(
            (string) config('communication.rabbitmq.pipeline_events.retry_exchange'),
            $this->retryRoutingKey($eventType),
            $retryEvent,
        );

        return $retryEvent;
    }

    public function publishFailed(array $event, Throwable $error): array
    {
        $failed = PipelineEvent::normalize(PipelineEvent::JOB_FAILED, [
            'task_id' => $event['task_id'] ?? null,
            'job_id' => $event['job_id'] ?? null,
            'parent_job_id' => $event['parent_job_id'] ?? null,
            'dataset_id' => $event['dataset_id'] ?? null,
            'job_type' => $event['job_type'] ?? null,
            'source_url' => $event['source_url'] ?? null,
            'local_path' => $event['local_path'] ?? null,
            'content_hash' => $event['content_hash'] ?? null,
            'status' => 'failed',
            'metadata' => [
                'error_type' => class_basename($error),
                'error_message' => $error->getMessage(),
                'original_event_type' => $event['event_type'] ?? null,
                'original_event_payload' => $event,
                'retry_count' => (int) ($event['retry_count'] ?? 0),
                'max_retries' => (int) ($event['max_retries'] ?? config('communication.rabbitmq.pipeline_events.max_retries', 3)),
            ],
        ]);

        $this->log('failed', $failed);
        $this->recorder->record(PipelineEvent::JOB_FAILED, $failed, 'rabbitmq.failed');

        if ((bool) config('communication.rabbitmq.pipeline_events.enabled', true)) {
            $this->declareFailedTopology();
            $this->publishRaw(
                (string) config('communication.rabbitmq.pipeline_events.failed_exchange'),
                (string) config('communication.rabbitmq.pipeline_events.failed_routing_key', PipelineEvent::JOB_FAILED),
                $failed,
            );
        }

        return $failed;
    }

    public function declareWorkerTopology(string $worker): array
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

        $this->declareFailedTopology();

        return [
            'queue' => $queue,
            'consumer_tag' => (string) ($cfg['consumer_tag'] ?? "hawki-rag-{$worker}-events"),
            'listen' => $events,
        ];
    }

    public function declareFailedTopology(): array
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

    public function decode(string $body): array
    {
        $event = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($event)) {
            throw new \JsonException('Pipeline event payload must be a JSON object.');
        }

        return PipelineEvent::normalize((string) ($event['event_type'] ?? ''), $event);
    }

    public function log(string $action, array $event): void
    {
        Log::info('pipeline.event', [
            'action' => $action,
            'event_type' => $event['event_type'] ?? null,
            'task_id' => $event['task_id'] ?? null,
            'job_id' => $event['job_id'] ?? null,
            'parent_job_id' => $event['parent_job_id'] ?? null,
            'job_type' => $event['job_type'] ?? null,
            'status' => $event['status'] ?? null,
            'source_url' => $event['source_url'] ?? null,
            'local_path' => $event['local_path'] ?? null,
            'retry_count' => $event['retry_count'] ?? 0,
        ]);
    }

    private function declareExchanges(): void
    {
        $channel = $this->rabbit->channel();
        $cfg = config('communication.rabbitmq.pipeline_events');

        $channel->exchange_declare((string) $cfg['exchange'], (string) $cfg['exchange_type'], false, true, false);
        $channel->exchange_declare((string) $cfg['retry_exchange'], (string) $cfg['retry_exchange_type'], false, true, false);
        $channel->exchange_declare((string) $cfg['failed_exchange'], (string) $cfg['failed_exchange_type'], false, true, false);
    }

    private function declareRoutableEventTopology(string $eventType): void
    {
        $this->declareExchanges();

        foreach ((array) config('communication.rabbitmq.pipeline_events.workers', []) as $worker => $cfg) {
            if (!is_array($cfg)) {
                continue;
            }

            $events = array_values(array_filter(array_map('strval', $cfg['listen'] ?? [])));
            if (in_array($eventType, $events, true)) {
                $this->declareWorkerTopology((string) $worker);
            }
        }
    }

    private function declareRetryQueue(string $workerQueue, string $eventType): void
    {
        $channel = $this->rabbit->channel();
        $retryQueue = $workerQueue . '.retry.' . str_replace(['.', ':'], '_', $eventType);
        $arguments = [
            'x-message-ttl' => (int) config('communication.rabbitmq.pipeline_events.retry_delay_ms', 5000),
            'x-dead-letter-exchange' => (string) config('communication.rabbitmq.pipeline_events.exchange'),
            'x-dead-letter-routing-key' => $eventType,
        ];

        if ((string) config('communication.rabbitmq.pipeline_events.queue_type', 'quorum') === 'quorum') {
            $arguments['x-queue-type'] = 'quorum';
        }

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

    private function publishRaw(string $exchange, string $routingKey, array $payload): void
    {
        $message = new AMQPMessage(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]);

        $this->rabbit->channel()->basic_publish($message, $exchange, $routingKey);
    }
}
