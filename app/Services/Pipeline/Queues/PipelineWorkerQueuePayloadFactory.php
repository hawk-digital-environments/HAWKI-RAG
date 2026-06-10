<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Queues;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineWorkerQueuePayloadFactory
{
    public function __construct(private PipelineQueuePayloadFactory $queues)
    {
    }

    /**
     * @param array{worker: string, queueName: string, listen: list<string>, retryQueues: list<string>} $worker
     * @param array<string, array<string, mixed>> $queues
     * @return array<string, mixed>
     */
    public function make(array $worker, array $queues): array
    {
        $queue = $this->queues->make($worker['queueName'], $queues[$worker['queueName']] ?? null);
        $retryQueues = array_map(
            fn (string $name): array => $this->queues->make($name, $queues[$name] ?? null),
            $worker['retryQueues'],
        );
        $retryReady = array_sum(array_column($retryQueues, 'readyMessages'));
        $retryUnacked = array_sum(array_column($retryQueues, 'unackedMessages'));
        $warnings = $this->workerWarnings($queue, $retryReady + $retryUnacked);

        return [
            'worker' => $worker['worker'],
            'queueName' => $worker['queueName'],
            'listen' => $worker['listen'],
            'readyMessages' => $queue['readyMessages'],
            'unackedMessages' => $queue['unackedMessages'],
            'consumers' => $queue['consumers'],
            'retryQueueCount' => $retryReady + $retryUnacked,
            'retryReadyMessages' => $retryReady,
            'retryUnackedMessages' => $retryUnacked,
            'failedQueueCount' => 0,
            'failedReadyMessages' => 0,
            'failedUnackedMessages' => 0,
            'status' => $warnings === [] ? 'ok' : 'warn',
            'warnings' => $warnings,
            'queue' => $queue,
            'retryQueues' => $retryQueues,
        ];
    }

    /**
     * @param array{workers: list<array{worker: string, queueName: string, listen: list<string>, retryQueues: list<string>}>, failedQueue: string} $expected
     * @return list<array<string, mixed>>
     */
    public function missing(array $expected): array
    {
        return array_map(function (array $worker): array {
            return [
                'worker' => $worker['worker'],
                'queueName' => $worker['queueName'],
                'listen' => $worker['listen'],
                'readyMessages' => 0,
                'unackedMessages' => 0,
                'consumers' => 0,
                'retryQueueCount' => 0,
                'retryReadyMessages' => 0,
                'retryUnackedMessages' => 0,
                'failedQueueCount' => 0,
                'failedReadyMessages' => 0,
                'failedUnackedMessages' => 0,
                'status' => 'fail',
                'warnings' => ['RabbitMQ management API is not reachable.'],
                'queue' => $this->queues->make($worker['queueName'], null),
                'retryQueues' => array_map(fn (string $name): array => $this->queues->make($name, null), $worker['retryQueues']),
            ];
        }, $expected['workers']);
    }

    /**
     * @param array<string, mixed> $queue
     * @return list<string>
     */
    private function workerWarnings(array $queue, int $retryCount): array
    {
        $warnings = [];

        if (! $queue['exists']) {
            $warnings[] = 'Worker queue is missing. Start the worker or run php artisan pipeline:declare-event-topology.';
        }

        if ($queue['readyMessages'] > 0 && $queue['consumers'] < 1) {
            $warnings[] = 'Messages are ready but no consumers are attached.';
        }

        if ($queue['unackedMessages'] > 0 && $queue['consumers'] < 1) {
            $warnings[] = 'Messages are unacked and no consumers are attached.';
        }

        if ($retryCount > 0) {
            $warnings[] = 'Retry queues contain messages.';
        }

        return $warnings;
    }
}
