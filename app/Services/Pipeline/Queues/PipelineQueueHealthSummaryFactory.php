<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Queues;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineQueueHealthSummaryFactory
{
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

        if (! $failedQueue['exists']) {
            $warnings[] = 'Failed queue is missing. Start a pipeline worker or run php artisan pipeline:declare-event-topology.';
        }

        $failedCount = $failedQueue['readyMessages'] + $failedQueue['unackedMessages'];
        if ($failedCount > 0) {
            $warnings[] = 'Failed queue contains '.$failedCount.' failed event'.($failedCount === 1 ? '' : 's').' awaiting recovery.';
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
}
