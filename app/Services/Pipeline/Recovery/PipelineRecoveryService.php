<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Recovery;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineRecoveryService
{
    public function __construct(
        private PipelineRecoveryListService $lists,
        private PipelineRecoveryRetryCoordinator $retries,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function failedJobs(array $filters = []): array
    {
        return $this->lists->failedJobs($filters);
    }

    /**
     * @param list<mixed> $jobIds
     * @return array<string, mixed>
     */
    public function retrySelected(array $jobIds): array
    {
        return $this->retries->retrySelected($jobIds);
    }

    /**
     * @return array<string, mixed>
     */
    public function retryJob(string $jobId): array
    {
        return $this->retries->retryJob($jobId);
    }

    /**
     * @return array<string, mixed>
     */
    public function retryAll(): array
    {
        return $this->retries->retryAll();
    }

    /**
     * @return array<string, mixed>
     */
    public function retryTask(string $taskId): array
    {
        return $this->retries->retryTask($taskId);
    }

    /**
     * @return array<string, mixed>
     */
    public function retryHeap(string $heapId): array
    {
        return $this->retries->retryHeap($heapId);
    }
}
