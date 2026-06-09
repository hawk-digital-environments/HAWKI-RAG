<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Events;

use App\Services\Pipeline\EventHandlers\PipelineEventHandler;
use App\Services\Rag\RagRabbitMQ;
use App\Support\PipelineExitCode;
use Illuminate\Console\Command;
use Illuminate\Container\Attributes\Singleton;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

#[Singleton]
readonly class PipelineEventWorker
{
    public function __construct(
        private readonly RagRabbitMQ $rabbit,
        private readonly PipelineEventBus $bus,
        private readonly PipelineEventMessageProcessor $processor,
        private readonly ?PipelineEventConfig $config = null,
    ) {}

    public function run(Command $command, string $worker, PipelineEventHandler $handler): int
    {
        $topology = $this->bus->declareWorkerTopology($worker);
        $channel = $this->rabbit->channel();
        $channel->basic_qos(0, $this->prefetchCount(), false);
        $shouldStop = false;
        $lastExitCode = PipelineExitCode::SUCCESS;

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use (&$shouldStop): void {
                $shouldStop = true;
            });
            pcntl_signal(SIGINT, static function () use (&$shouldStop): void {
                $shouldStop = true;
            });
        }

        $processed = 0;
        $channel->basic_consume(
            $topology['queue'],
            $topology['consumer_tag'],
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($command, $handler, &$processed, &$shouldStop, &$lastExitCode): void {
                $lastExitCode = $this->processor->process($command, $message, $handler);
                $processed++;
                if ($command->option('once')) {
                    $shouldStop = true;
                }
            },
        );

        $command->info("Pipeline {$worker} event worker listening on {$topology['queue']}.");

        try {
            while (! $shouldStop && $channel->is_consuming()) {
                try {
                    $channel->wait(null, false, (int) $command->option('timeout'));
                } catch (AMQPTimeoutException) {
                    if ($command->option('once') && $processed === 0) {
                        $command->line('No message received before timeout.');
                        $lastExitCode = PipelineExitCode::PARTIAL_SUCCESS;
                        break;
                    }
                }
            }
        } finally {
            $this->rabbit->close();
        }

        return $command->option('once') ? $lastExitCode : PipelineExitCode::SUCCESS;
    }

    private function prefetchCount(): int
    {
        return $this->config?->prefetchCount() ?? 1;
    }
}
