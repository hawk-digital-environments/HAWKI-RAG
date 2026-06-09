<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Health;

use App\Services\Pipeline\Events\PipelineEventConfig;
use App\Services\Rag\RagRabbitMQ;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class PipelineRabbitHealthCheck
{
    public function __construct(
        private ConfigRepository $config,
        private PipelineEventConfig $events,
        private RagRabbitMQ $rabbit,
    ) {
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function check(): array
    {
        if (! $this->events->enabled()) {
            return $this->failureResult(
                'RabbitMQ',
                'Pipeline events are disabled.',
                'Set RABBITMQ_PIPELINE_EVENTS_ENABLED=true and start rabbitmq.',
            );
        }

        try {
            $this->rabbit->channel();
            $this->rabbit->close();

            return $this->ok(
                'RabbitMQ',
                sprintf(
                    'Connected to %s:%s, exchange %s.',
                    $this->config->get('communication.rabbitmq.host'),
                    $this->config->get('communication.rabbitmq.port'),
                    $this->events->exchange(),
                ),
            );
        } catch (\Throwable $exception) {
            return $this->failureResult(
                'RabbitMQ',
                $exception->getMessage(),
                'Start rabbitmq and verify RABBITMQ_HOST, RABBITMQ_PORT, RABBITMQ_USER, RABBITMQ_PASSWORD, and RABBITMQ_VHOST.',
            );
        }
    }

    private function ok(string $name, string $detail): array
    {
        return [
            'name' => $name,
            'status' => 'ok',
            'detail' => $detail,
            'fix' => '',
        ];
    }

    private function failureResult(string $name, string $detail, string $fix): array
    {
        return [
            'name' => $name,
            'status' => 'fail',
            'detail' => $detail,
            'fix' => $fix,
        ];
    }
}
