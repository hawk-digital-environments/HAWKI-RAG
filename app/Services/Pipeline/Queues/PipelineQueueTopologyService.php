<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Queues;

use Illuminate\Container\Attributes\Singleton;
use App\Services\Pipeline\Events\PipelineEventConfig;

#[Singleton]
readonly class PipelineQueueTopologyService
{
    public function __construct(private PipelineEventConfig $config)
    {
    }

    /**
     * @return array{workers: list<array{worker: string, queueName: string, listen: list<string>, retryQueues: list<string>}>, failedQueue: string}
     */
    public function expectedQueues(): array
    {
        $workers = [];
        foreach ($this->config->workers() as $name => $config) {
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
            'failedQueue' => $this->config->failedQueue(),
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
