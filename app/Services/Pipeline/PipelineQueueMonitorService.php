<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineQueueMonitorService
{
    public function __construct(
        private PipelineQueueMonitorClient $client,
        private PipelineQueueTopologyService $topology,
        private PipelineQueueHealthPayloadService $payloads,
    ) {
    }

    public function status(int $timeout = 5): array
    {
        $timeout = max(1, min(30, $timeout));
        $expected = $this->topology->expectedQueues();

        try {
            $queues = $this->client->fetchQueues($timeout);
        } catch (\Throwable $exception) {
            return [
                'status' => 'fail',
                'checkedAt' => now()->toIso8601String(),
                'managementUrl' => $this->client->managementUrl(),
                'message' => 'RabbitMQ management API is not reachable.',
                'fix' => 'Start rabbitmq management and verify RABBITMQ_MANAGEMENT_URL, RABBITMQ_USER, RABBITMQ_PASSWORD, and RABBITMQ_VHOST.',
                'error' => $exception->getMessage(),
                'workers' => $this->payloads->missingWorkers($expected),
                'failedQueue' => $this->payloads->queue($expected['failedQueue'], null),
                'totals' => $this->payloads->emptyTotals(),
                'warnings' => ['RabbitMQ management API is not reachable: ' . $exception->getMessage()],
            ];
        }

        $failedQueue = $this->payloads->queue($expected['failedQueue'], $queues[$expected['failedQueue']] ?? null);
        $workers = array_map(
            fn (array $worker): array => $this->payloads->worker($worker, $queues),
            $expected['workers'],
        );
        $warnings = $this->payloads->warnings($workers, $failedQueue);

        return [
            'status' => $warnings === [] ? 'ok' : 'warn',
            'checkedAt' => now()->toIso8601String(),
            'managementUrl' => $this->client->managementUrl(),
            'message' => $warnings === [] ? 'Pipeline queues are healthy.' : 'Pipeline queues need attention.',
            'workers' => $workers,
            'failedQueue' => $failedQueue,
            'totals' => $this->payloads->totals($workers, $failedQueue),
            'warnings' => $warnings,
        ];
    }
}
