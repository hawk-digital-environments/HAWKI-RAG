<?php

declare(strict_types=1);

namespace App\Services\Rag;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class RagRabbitMQConfig
{
    public function __construct(private ConfigRepository $config)
    {
    }

    public function host(): string
    {
        return (string) $this->config->get('communication.rabbitmq.host', 'rabbitmq');
    }

    public function port(): int
    {
        return (int) $this->config->get('communication.rabbitmq.port', 5672);
    }

    public function user(): string
    {
        return (string) $this->config->get('communication.rabbitmq.user', 'guest');
    }

    public function password(): string
    {
        return (string) $this->config->get('communication.rabbitmq.password', 'guest');
    }

    public function vhost(): string
    {
        return (string) $this->config->get('communication.rabbitmq.vhost', '/');
    }

    public function connectionTimeout(): float
    {
        return (float) $this->config->get('communication.rabbitmq.connection_timeout', 30);
    }

    public function readWriteTimeout(): float
    {
        return (float) $this->config->get('communication.rabbitmq.read_write_timeout', 30);
    }

    public function heartbeat(): int
    {
        return (int) $this->config->get('communication.rabbitmq.heartbeat', 30);
    }
}
