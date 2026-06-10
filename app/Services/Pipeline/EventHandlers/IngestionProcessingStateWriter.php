<?php

declare(strict_types=1);

namespace App\Services\Pipeline\EventHandlers;

use App\Services\Pipeline\Repositories\PipelineIngestionRepository;
use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class IngestionProcessingStateWriter
{
    public function __construct(
        private PipelineIngestionRepository $ingestion,
        #[Config('communication.rabbitmq.pipeline_events.max_retries')]
        private int $maxRetries = 3,
    ) {
    }

    /**
     * @param array<string, mixed> $event
     */
    public function mark(array $event, string $status): void
    {
        $this->ingestion->upsertProcessingState($event, $status, $this->maxRetries);
    }

    /**
     * @param array<string, mixed> $event
     */
    public function failed(array $event, \Throwable $error, int $retryCount, int $maxRetries): void
    {
        $this->ingestion->upsertFailedProcessingState($event, $error, $retryCount, $maxRetries);
    }
}
