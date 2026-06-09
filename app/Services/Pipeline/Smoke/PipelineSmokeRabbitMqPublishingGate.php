<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Smoke;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class PipelineSmokeRabbitMqPublishingGate
{
    public function __construct(
        private ConfigRepository $config,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get('communication.rabbitmq.pipeline_events.enabled', true);
    }

    public function withoutPublishing(callable $callback): mixed
    {
        $enabled = $this->enabled();
        $this->config->set('communication.rabbitmq.pipeline_events.enabled', false);

        try {
            return $callback();
        } finally {
            $this->config->set('communication.rabbitmq.pipeline_events.enabled', $enabled);
        }
    }
}
