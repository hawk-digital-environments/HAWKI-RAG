<?php

namespace App\Services\Pipeline;

use App\Services\Pipeline\EventHandlers\PipelineEventHandler;
use App\Services\Rag\RagRabbitMQ;
use App\Support\PipelineExitCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class PipelineEventWorker
{
    private bool $shouldStop = false;
    private int $lastExitCode = PipelineExitCode::SUCCESS;

    public function __construct(
        private readonly RagRabbitMQ $rabbit,
        private readonly PipelineEventBus $bus,
    ) {
    }

    public function run(Command $command, string $worker, PipelineEventHandler $handler): int
    {
        $topology = $this->bus->declareWorkerTopology($worker);
        $channel = $this->rabbit->channel();
        $channel->basic_qos(0, (int) config('communication.rabbitmq.pipeline_events.prefetch_count', 1), false);

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        }

        $processed = 0;
        $channel->basic_consume(
            $topology['queue'],
            $topology['consumer_tag'],
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($command, $handler, &$processed): void {
                $this->lastExitCode = $this->processMessage($command, $message, $handler);
                $processed++;
                if ($command->option('once')) {
                    $this->shouldStop = true;
                }
            },
        );

        $command->info("Pipeline {$worker} event worker listening on {$topology['queue']}.");

        try {
            while (!$this->shouldStop && $channel->is_consuming()) {
                try {
                    $channel->wait(null, false, (int) $command->option('timeout'));
                } catch (AMQPTimeoutException) {
                    if ($command->option('once') && $processed === 0) {
                        $command->line('No message received before timeout.');
                        $this->lastExitCode = PipelineExitCode::PARTIAL_SUCCESS;
                        break;
                    }
                }
            }
        } finally {
            $this->rabbit->close();
        }

        return $command->option('once') ? $this->lastExitCode : PipelineExitCode::SUCCESS;
    }

    private function processMessage(Command $command, AMQPMessage $message, PipelineEventHandler $handler): int
    {
        $event = [];
        $retryCount = 0;
        $maxRetries = (int) config('communication.rabbitmq.pipeline_events.max_retries', 3);

        try {
            $event = $this->bus->decode($message->getBody());
            $retryCount = (int) ($event['retry_count'] ?? 0);
            $maxRetries = (int) ($event['max_retries'] ?? $maxRetries);

            if (!in_array((string) $event['event_type'], $handler->eventTypes(), true)) {
                $message->ack();
                return PipelineExitCode::SUCCESS;
            }

            $this->bus->log('consume', $event);
            $handler->handle($event);
            $message->ack();
            $command->info("Processed {$event['event_type']} for job {$event['job_id']}.");

            return PipelineExitCode::SUCCESS;
        } catch (Throwable $error) {
            Log::warning('Pipeline event worker failed', [
                'event_type' => $event['event_type'] ?? null,
                'task_id' => $event['task_id'] ?? null,
                'job_id' => $event['job_id'] ?? null,
                'retry_count' => $retryCount,
                'max_retries' => $maxRetries,
                'error' => $error->getMessage(),
            ]);

            if ($event !== []) {
                $retry = $this->bus->publishRetry($event, $error);
                if ($retry !== null) {
                    $handler->failed($event, $error, $retryCount, $maxRetries);
                    $message->ack();
                    $command->warn("Retry published for {$event['event_type']} job {$event['job_id']}.");

                    return PipelineExitCode::RUNTIME_FAILURE;
                }

                $handler->failed($event, $error, $maxRetries, $maxRetries);
                $this->bus->publishFailed($event, $error);
            }

            $message->ack();
            $command->error('Pipeline event failed: ' . $error->getMessage());

            return PipelineExitCode::RUNTIME_FAILURE;
        }
    }
}
