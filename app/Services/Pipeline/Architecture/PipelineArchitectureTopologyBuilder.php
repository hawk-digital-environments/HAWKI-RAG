<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Architecture;

use App\Services\Pipeline\Events\PipelineEventConfig;
use App\Services\Pipeline\Queues\PipelineQueueTopologyService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineArchitectureTopologyBuilder
{
    public function __construct(
        private PipelineQueueTopologyService $queues,
        private PipelineEventConfig $config,
    ) {
    }

    public function topology(): array
    {
        return [
            'eventsExchange' => $this->config->exchange(),
            'retryExchange' => $this->config->retryExchange(),
            'failedExchange' => $this->config->failedExchange(),
            'retryDelayMs' => $this->config->retryDelayMs(),
            'maxRetries' => $this->config->maxRetries(),
            'queueType' => $this->config->queueType(),
            'failedRoutingKey' => $this->config->failedRoutingKey(),
            'queues' => $this->queues->expectedQueues(),
        ];
    }
}
