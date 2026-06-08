<?php

namespace App\Services\Pipeline;

use Illuminate\Support\Facades\Http;
use Throwable;

class PipelineQueueMonitorService
{
    public function status(int $timeout = 5): array
    {
        $timeout = max(1, min(30, $timeout));
        $expected = $this->expectedQueues();

        try {
            $queues = $this->fetchQueues($timeout);
        } catch (Throwable $exception) {
            return [
                'status' => 'fail',
                'checkedAt' => now()->toIso8601String(),
                'managementUrl' => $this->managementUrl(),
                'message' => 'RabbitMQ management API is not reachable.',
                'fix' => 'Start rabbitmq management and verify RABBITMQ_MANAGEMENT_URL, RABBITMQ_USER, RABBITMQ_PASSWORD, and RABBITMQ_VHOST.',
                'error' => $exception->getMessage(),
                'workers' => $this->missingWorkerPayloads($expected),
                'failedQueue' => $this->queuePayload($expected['failedQueue'], null),
                'totals' => $this->emptyTotals(),
                'warnings' => ['RabbitMQ management API is not reachable: ' . $exception->getMessage()],
            ];
        }

        $failedQueue = $this->queuePayload($expected['failedQueue'], $queues[$expected['failedQueue']] ?? null);
        $workers = array_map(
            fn (array $worker): array => $this->workerPayload($worker, $queues),
            $expected['workers'],
        );
        $warnings = $this->collectWarnings($workers, $failedQueue);

        return [
            'status' => $warnings === [] ? 'ok' : 'warn',
            'checkedAt' => now()->toIso8601String(),
            'managementUrl' => $this->managementUrl(),
            'message' => $warnings === [] ? 'Pipeline queues are healthy.' : 'Pipeline queues need attention.',
            'workers' => $workers,
            'failedQueue' => $failedQueue,
            'totals' => $this->totals($workers, $failedQueue),
            'warnings' => $warnings,
        ];
    }

    private function fetchQueues(int $timeout): array
    {
        $url = $this->managementUrl();
        if ($url === '') {
            throw new \RuntimeException('RABBITMQ_MANAGEMENT_URL is empty.');
        }

        $response = Http::timeout($timeout)
            ->connectTimeout($timeout)
            ->withBasicAuth(
                (string) config('communication.rabbitmq.user', 'guest'),
                (string) config('communication.rabbitmq.password', 'guest'),
            )
            ->acceptJson()
            ->get($url . '/api/queues/' . rawurlencode((string) config('communication.rabbitmq.vhost', '/')));

        if (!$response->successful()) {
            throw new \RuntimeException("HTTP {$response->status()} from {$url}/api/queues.");
        }

        $queues = $response->json();
        if (!is_array($queues)) {
            throw new \RuntimeException('RabbitMQ management API returned an invalid queue payload.');
        }

        $byName = [];
        foreach ($queues as $queue) {
            if (is_array($queue) && is_string($queue['name'] ?? null)) {
                $byName[$queue['name']] = $queue;
            }
        }

        return $byName;
    }

    private function expectedQueues(): array
    {
        $workers = [];
        foreach ((array) config('communication.rabbitmq.pipeline_events.workers', []) as $name => $config) {
            if (!is_array($config)) {
                continue;
            }

            $queue = $this->stringValue($config['queue'] ?? null);
            $events = array_values(array_filter(array_map('strval', (array) ($config['listen'] ?? []))));
            if (!$queue) {
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

    private function workerPayload(array $worker, array $queues): array
    {
        $queue = $this->queuePayload($worker['queueName'], $queues[$worker['queueName']] ?? null);
        $retryQueues = array_map(
            fn (string $name): array => $this->queuePayload($name, $queues[$name] ?? null),
            $worker['retryQueues'],
        );
        $retryReady = array_sum(array_column($retryQueues, 'readyMessages'));
        $retryUnacked = array_sum(array_column($retryQueues, 'unackedMessages'));
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

        if ($retryReady + $retryUnacked > 0) {
            $warnings[] = 'Retry queues contain messages.';
        }

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

    private function queuePayload(string $name, ?array $queue): array
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

    private function missingWorkerPayloads(array $expected): array
    {
        return array_map(function (array $worker) use ($expected): array {
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
                'queue' => $this->queuePayload($worker['queueName'], null),
                'retryQueues' => array_map(fn (string $name): array => $this->queuePayload($name, null), $worker['retryQueues']),
            ];
        }, $expected['workers']);
    }

    private function collectWarnings(array $workers, array $failedQueue): array
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

    private function totals(array $workers, array $failedQueue): array
    {
        return [
            'readyMessages' => array_sum(array_column($workers, 'readyMessages')),
            'unackedMessages' => array_sum(array_column($workers, 'unackedMessages')),
            'consumers' => array_sum(array_column($workers, 'consumers')),
            'retryQueueCount' => array_sum(array_column($workers, 'retryQueueCount')),
            'failedQueueCount' => $failedQueue['readyMessages'] + $failedQueue['unackedMessages'],
        ];
    }

    private function emptyTotals(): array
    {
        return [
            'readyMessages' => 0,
            'unackedMessages' => 0,
            'consumers' => 0,
            'retryQueueCount' => 0,
            'failedQueueCount' => 0,
        ];
    }

    private function retryQueueName(string $workerQueue, string $eventType): string
    {
        return $workerQueue . '.retry.' . str_replace(['.', ':'], '_', $eventType);
    }

    private function managementUrl(): string
    {
        return rtrim((string) config('communication.rabbitmq.management_url', ''), '/');
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
