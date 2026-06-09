<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Queues;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineQueueTopologyService
{
    /**
     * @return array{workers: list<array{worker: string, queueName: string, listen: list<string>, retryQueues: list<string>}>, failedQueue: string}
     */
    public function expectedQueues(): array
    {
        $workers = [];
        foreach ((array) config('communication.rabbitmq.pipeline_events.workers', []) as $name => $config) {
            if (! is_array($config)) {
                continue;
            }

            $queue = $this->stringValue($config['queue'] ?? null);
            $events = array_values(array_filter(array_map('strval', (array) ($config['listen'] ?? []))));
            if (! $queue) {
                continue;
            }

            $workers[] = [
                'worker' => (string) $name,
                'queueName' => $queue,
                'listen' => $events,
                'retryQueues' => array_map(
                    fn (string $event): string => $this->retryQueueName($queue, $event),
                    $events,
                ),
            ];
        }

        return [
            'workers' => $workers,
            'failedQueue' => (string) config('communication.rabbitmq.pipeline_events.failed_queue', 'pipeline_failed_events'),
        ];
    }

    public function retryQueueName(string $workerQueue, string $eventType): string
    {
        return $workerQueue.'.retry.'.str_replace(['.', ':'], '_', $eventType);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
