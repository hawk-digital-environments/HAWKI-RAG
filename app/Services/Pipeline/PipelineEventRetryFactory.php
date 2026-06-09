<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineEventRetryFactory
{
    public function __construct(
        private PipelineEventNormalizer $normalizer,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    public function makeRetry(array $event, \Throwable $error): ?array
    {
        $eventType = (string) ($event['event_type'] ?? '');
        if ($eventType === '') {
            return null;
        }

        $retryCount = max(0, (int) ($event['retry_count'] ?? 0)) + 1;
        $maxRetries = max(0, (int) ($event['max_retries'] ?? config('communication.rabbitmq.pipeline_events.max_retries', 3)));
        if ($retryCount > $maxRetries) {
            return null;
        }

        return array_merge($event, [
            'retry_count' => $retryCount,
            'max_retries' => $maxRetries,
            'last_error_type' => class_basename($error),
            'last_error_message' => $error->getMessage(),
            'metadata' => array_merge($this->metadata($event), [
                'last_error_type' => class_basename($error),
                'last_error_message' => $error->getMessage(),
            ]),
        ]);
    }

    public function makeDelayed(array $event, string $reason): array
    {
        $eventType = (string) ($event['event_type'] ?? '');
        if ($eventType === '') {
            throw new \InvalidArgumentException('Delayed publish requires event_type.');
        }

        return $this->normalizer->normalize($eventType, array_merge($event, [
            'metadata' => array_merge($this->metadata($event), [
                'delay_reason' => $reason,
                'delay_requested_at' => $this->timestamp(),
            ]),
        ]));
    }

    public function makeRecoveryRetry(array $event, string $reason): array
    {
        $eventType = (string) ($event['event_type'] ?? '');
        if ($eventType === '') {
            throw new \InvalidArgumentException('Recovery retry requires event_type.');
        }

        return $this->normalizer->normalize($eventType, array_merge($event, [
            'metadata' => array_merge($this->metadata($event), [
                'recovery_reason' => $reason,
                'recovery_requested_at' => $this->timestamp(),
            ]),
        ]));
    }

    private function metadata(array $event): array
    {
        return is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format(\DateTimeInterface::ATOM);
    }
}
