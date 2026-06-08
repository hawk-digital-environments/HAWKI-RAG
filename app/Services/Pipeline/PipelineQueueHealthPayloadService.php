<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineQueueHealthPayloadService
{
    /**
     * @param array<string, mixed>|null $queue
     * @return array<string, mixed>
     */
    public function queue(string $name, ?array $queue): array
    {
        return [
            'name' => $name,
            'exists' => $queue !== null,
            'readyMessages' => (int) ($queue['messages_ready'] ?? 0),
            'unackedMessages' => (int) ($queue['messages_unacknowledged'] ?? 0),
            'messages' => (int) ($queue['messages'] ?? (($queue['messages_ready'] ?? 0) + ($queue['messages_unacknowledged'] ?? 0))),
            'consumers' => (int) ($queue['consumers'] ?? 0),
            'state' => $this->stringValue($queue['state'] ?? null) ?? ($queue ? 'unknown' : 'missing'),
        ];
    }

    /**
     * @param array{worker: string, queueName: string, listen: list<string>, retryQueues: list<string>} $worker
     * @param array<string, array<string, mixed>> $queues
     * @return array<string, mixed>
     */
    public function worker(array $worker, array $queues): array
    {
        $queue = $this->queue($worker['queueName'], $queues[$worker['queueName']] ?? null);
        $retryQueues = array_map(
            fn (string $name): array => $this->queue($name, $queues[$name] ?? null),
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
    public function missingWorkers(array $expected): array
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
                'queue' => $this->queue($worker['queueName'], null),
                'retryQueues' => array_map(fn (string $name): array => $this->queue($name, null), $worker['retryQueues']),
            ];
        }, $expected['workers']);
    }

    /**
     * @param list<array<string, mixed>> $workers
     * @param array<string, mixed> $failedQueue
     * @return list<string>
     */
    public function warnings(array $workers, array $failedQueue): array
    {
        $warnings = [];
        foreach ($workers as $worker) {
            foreach ($worker['warnings'] as $warning) {
                $warnings[] = "{$worker['worker']}: {$warning}";
            }
        }

        if (!$failedQueue['exists']) {
            $warnings[] = 'Failed queue is missing. Start a pipeline worker or run php artisan pipeline:declare-event-topology.';
        }

        $failedCount = $failedQueue['readyMessages'] + $failedQueue['unackedMessages'];
        if ($failedCount > 0) {
            $warnings[] = 'Failed queue contains ' . $failedCount . ' message' . ($failedCount === 1 ? '' : 's') . '.';
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @param list<array<string, mixed>> $workers
     * @param array<string, mixed> $failedQueue
     * @return array<string, int>
     */
    public function totals(array $workers, array $failedQueue): array
    {
        return [
            'readyMessages' => array_sum(array_column($workers, 'readyMessages')),
            'unackedMessages' => array_sum(array_column($workers, 'unackedMessages')),
            'consumers' => array_sum(array_column($workers, 'consumers')),
            'retryQueueCount' => array_sum(array_column($workers, 'retryQueueCount')),
            'failedQueueCount' => $failedQueue['readyMessages'] + $failedQueue['unackedMessages'],
        ];
    }

    /**
     * @return array<string, int>
     */
    public function emptyTotals(): array
    {
        return [
            'readyMessages' => 0,
            'unackedMessages' => 0,
            'consumers' => 0,
            'retryQueueCount' => 0,
            'failedQueueCount' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $queue
     * @return list<string>
     */
    private function workerWarnings(array $queue, int $retryCount): array
    {
        $warnings = [];

        if (!$queue['exists']) {
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

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
