<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use Illuminate\Container\Attributes\Singleton;
use Throwable;

#[Singleton]
class PipelineEventBus
{
    public function __construct(
        private readonly PipelineEventRecorder $recorder,
        private readonly PipelineEventTopologyService $topology,
        private readonly PipelineEventPublisher $publisher,
        private readonly PipelineFailedEventFactory $failedEvents,
        private readonly PipelineEventRetryFactory $retryEvents,
        private readonly PipelineEventLogger $logger,
        private readonly PipelineEventDecoder $decoder,
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
        $this->topology->declareForEvent($eventType);
        $this->publisher->publish((string) $cfg['exchange'], $eventType, $event);

        return $event;
    }

    public function publishRetry(array $event, Throwable $error): ?array
    {
        if (! (bool) config('communication.rabbitmq.pipeline_events.enabled', true)) {
            return null;
        }

        $retryEvent = $this->retryEvents->makeRetry($event, $error);
        if ($retryEvent === null) {
            return null;
        }

        $eventType = (string) $retryEvent['event_type'];

        $this->topology->declareForEvent($eventType);
        $this->publisher->publish(
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
        $delayedEvent = $this->retryEvents->makeDelayed($event, $reason);
        $eventType = (string) $delayedEvent['event_type'];

        $this->recorder->record($eventType, $delayedEvent, 'rabbitmq.delay', "Delayed event queued: {$eventType}");
        $this->log('delay', $delayedEvent);

        if (! (bool) config('communication.rabbitmq.pipeline_events.enabled', true)) {
            return $delayedEvent;
        }

        $this->topology->declareForEvent($eventType);
        $this->publisher->publish(
            (string) config('communication.rabbitmq.pipeline_events.retry_exchange'),
            $this->retryRoutingKey($eventType),
            $delayedEvent,
        );

        return $delayedEvent;
    }

    public function publishRecoveryRetry(array $event, string $reason = 'operator recovery retry'): array
    {
        $retryEvent = $this->retryEvents->makeRecoveryRetry($event, $reason);
        $eventType = (string) $retryEvent['event_type'];

        $this->recorder->record($eventType, $retryEvent, 'rabbitmq.recovery', "Recovery retry queued: {$eventType}");
        $this->log('recovery_retry', $retryEvent);

        if (! (bool) config('communication.rabbitmq.pipeline_events.enabled', true)) {
            return $retryEvent;
        }

        $this->topology->declareForEvent($eventType);
        $this->publisher->publish(
            (string) config('communication.rabbitmq.pipeline_events.retry_exchange'),
            $this->retryRoutingKey($eventType),
            $retryEvent,
        );

        return $retryEvent;
    }

    public function publishFailed(array $event, Throwable $error): array
    {
        $failed = $this->failedEvents->make($event, $error);

        $this->log('failed', $failed);
        $this->recorder->record(PipelineEvent::JOB_FAILED, $failed, 'rabbitmq.failed');

        if ((bool) config('communication.rabbitmq.pipeline_events.enabled', true)) {
            $this->declareFailedTopology();
            $this->publisher->publish(
                (string) config('communication.rabbitmq.pipeline_events.failed_exchange'),
                (string) config('communication.rabbitmq.pipeline_events.failed_routing_key', PipelineEvent::JOB_FAILED),
                $failed,
            );
        }

        return $failed;
    }

    public function declareWorkerTopology(string $worker): array
    {
        return $this->topology->declareWorker($worker);
    }

    public function declareFailedTopology(): array
    {
        return $this->topology->declareFailed();
    }

    public function decode(string $body): array
    {
        return $this->decoder->decode($body);
    }

    public function log(string $action, array $event): void
    {
        $this->logger->log($action, $event);
    }

    private function retryRoutingKey(string $eventType): string
    {
        return $eventType;
    }
}
