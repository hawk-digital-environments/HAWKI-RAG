<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Events;

use Illuminate\Container\Attributes\Singleton;
use Psr\Log\LoggerInterface;

#[Singleton]
readonly class PipelineEventLogger
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function log(string $action, array $event): void
    {
        $this->logger->info('pipeline.event', [
            'action' => $action,
            'event_type' => $event['event_type'] ?? null,
            'task_id' => $event['task_id'] ?? null,
            'job_id' => $event['job_id'] ?? null,
            'parent_job_id' => $event['parent_job_id'] ?? null,
            'job_type' => $event['job_type'] ?? null,
            'status' => $event['status'] ?? null,
            'source_url' => $event['source_url'] ?? null,
            'local_path' => $event['local_path'] ?? null,
            'retry_count' => $event['retry_count'] ?? 0,
        ]);
    }

    public function workerFailed(array $event, int $retryCount, int $maxRetries, \Throwable $error): void
    {
        $this->logger->warning('Pipeline event worker failed', [
            'event_type' => $event['event_type'] ?? null,
            'task_id' => $event['task_id'] ?? null,
            'job_id' => $event['job_id'] ?? null,
            'retry_count' => $retryCount,
            'max_retries' => $maxRetries,
            'error' => $error->getMessage(),
            'exception' => $error,
        ]);
    }
}
