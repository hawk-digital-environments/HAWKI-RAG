<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Events;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

class PipelineEventConfig
{
    public function __construct(private readonly ConfigRepository $config)
    {
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get('communication.rabbitmq.pipeline_events.enabled', true);
    }

    public function exchange(): string
    {
        return (string) $this->config->get('communication.rabbitmq.pipeline_events.exchange', 'pipeline.events');
    }

    public function exchangeType(): string
    {
        return (string) $this->config->get('communication.rabbitmq.pipeline_events.exchange_type', 'direct');
    }

    public function retryExchange(): string
    {
        return (string) $this->config->get('communication.rabbitmq.pipeline_events.retry_exchange', 'pipeline.retry');
    }

    public function retryExchangeType(): string
    {
        return (string) $this->config->get('communication.rabbitmq.pipeline_events.retry_exchange_type', 'direct');
    }

    public function failedExchange(): string
    {
        return (string) $this->config->get('communication.rabbitmq.pipeline_events.failed_exchange', 'pipeline.failures');
    }

    public function failedExchangeType(): string
    {
        return (string) $this->config->get('communication.rabbitmq.pipeline_events.failed_exchange_type', 'direct');
    }

    public function failedQueue(): string
    {
        return (string) $this->config->get('communication.rabbitmq.pipeline_events.failed_queue', 'pipeline_failed_events');
    }

    public function failedRoutingKey(): string
    {
        return (string) $this->config->get('communication.rabbitmq.pipeline_events.failed_routing_key', PipelineEvent::JOB_FAILED);
    }

    public function retryDelayMs(): int
    {
        return (int) $this->config->get('communication.rabbitmq.pipeline_events.retry_delay_ms', 5000);
    }

    public function queueType(): string
    {
        return (string) $this->config->get('communication.rabbitmq.pipeline_events.queue_type', 'quorum');
    }

    public function usesQuorumQueues(): bool
    {
        return $this->queueType() === 'quorum';
    }

    public function workers(): array
    {
        return (array) $this->config->get('communication.rabbitmq.pipeline_events.workers', []);
    }

    public function worker(string $worker): ?array
    {
        $config = $this->config->get("communication.rabbitmq.pipeline_events.workers.{$worker}");

        return is_array($config) ? $config : null;
    }

    public function rabbitUser(): string
    {
        return (string) $this->config->get('communication.rabbitmq.user', 'guest');
    }

    public function rabbitPassword(): string
    {
        return (string) $this->config->get('communication.rabbitmq.password', 'guest');
    }

    public function rabbitVhost(): string
    {
        return (string) $this->config->get('communication.rabbitmq.vhost', '/');
    }

    public function rabbitManagementUrl(): string
    {
        return rtrim((string) $this->config->get('communication.rabbitmq.management_url', ''), '/');
    }
}
