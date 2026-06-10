<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Queues;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineQueueHealthPayloadService
{
    public function __construct(
        private PipelineQueuePayloadFactory $queues,
        private PipelineWorkerQueuePayloadFactory $workers,
        private PipelineQueueHealthSummaryFactory $summary,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $queue
     * @return array<string, mixed>
     */
    public function queue(string $name, ?array $queue): array
    {
        return $this->queues->make($name, $queue);
    }

    /**
     * @param  array{worker: string, queueName: string, listen: list<string>, retryQueues: list<string>}  $worker
     * @param  array<string, array<string, mixed>>  $queues
     * @return array<string, mixed>
     */
    public function worker(array $worker, array $queues): array
    {
        return $this->workers->make($worker, $queues);
    }

    /**
     * @param  array{workers: list<array{worker: string, queueName: string, listen: list<string>, retryQueues: list<string>}>, failedQueue: string}  $expected
     * @return list<array<string, mixed>>
     */
    public function missingWorkers(array $expected): array
    {
        return $this->workers->missing($expected);
    }

    /**
     * @param  list<array<string, mixed>>  $workers
     * @param  array<string, mixed>  $failedQueue
     * @return list<string>
     */
    public function warnings(array $workers, array $failedQueue): array
    {
        return $this->summary->warnings($workers, $failedQueue);
    }

    /**
     * @param  list<array<string, mixed>>  $workers
     * @param  array<string, mixed>  $failedQueue
     * @return array<string, int>
     */
    public function totals(array $workers, array $failedQueue): array
    {
        return $this->summary->totals($workers, $failedQueue);
    }

    /**
     * @return array<string, int>
     */
    public function emptyTotals(): array
    {
        return $this->summary->emptyTotals();
    }
}
