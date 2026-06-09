<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Services\Pipeline\Events\PipelineEventBus;
use Illuminate\Container\Attributes\Singleton;
use Psr\Log\LoggerInterface;

#[Singleton]
readonly class PipelineTaskEventPublisher
{
    public function __construct(
        private PipelineEventBus $eventBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function publish(string $routingKey, array $payload): void
    {
        try {
            $this->eventBus->publish($routingKey, $payload);
        } catch (\Throwable $exception) {
            $this->logger->warning('Pipeline RabbitMQ event publish failed.', [
                'routing_key' => $routingKey,
                'error' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }
}
