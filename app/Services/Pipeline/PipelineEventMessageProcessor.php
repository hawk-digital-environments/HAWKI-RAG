<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Services\Pipeline\EventHandlers\PipelineEventHandler;
use App\Support\PipelineExitCode;
use Illuminate\Console\Command;
use Illuminate\Container\Attributes\Singleton;
use PhpAmqpLib\Message\AMQPMessage;

#[Singleton]
readonly class PipelineEventMessageProcessor
{
    public function __construct(
        private PipelineEventDecoder $decoder,
        private PipelineEventBus $bus,
        private PipelineEventLogger $logger,
    ) {
    }

    public function process(Command $command, AMQPMessage $message, PipelineEventHandler $handler): int
    {
        $event = [];
        $retryCount = 0;
        $maxRetries = (int) config('communication.rabbitmq.pipeline_events.max_retries', 3);

        try {
            $event = $this->decoder->decode($message->getBody());
            $retryCount = (int) ($event['retry_count'] ?? 0);
            $maxRetries = (int) ($event['max_retries'] ?? $maxRetries);

            if (!in_array((string) $event['event_type'], $handler->eventTypes(), true)) {
                $message->ack();
                return PipelineExitCode::SUCCESS;
            }

            $this->logger->log('consume', $event);
            $handler->handle($event);
            $message->ack();
            $command->info("Processed {$event['event_type']} for job {$event['job_id']}.");

            return PipelineExitCode::SUCCESS;
        } catch (\Throwable $error) {
            $this->logger->workerFailed($event, $retryCount, $maxRetries, $error);

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
