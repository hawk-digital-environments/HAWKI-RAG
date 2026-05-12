<?php

namespace App\Console\Commands\Rag;

use App\Services\Rag\ConvertedDocumentIngestionService;
use App\Services\Rag\RagRabbitMQ;
use App\Support\PipelineExitCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use JsonException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class RagIngestionRabbitWorkerCommand extends Command
{
    protected $signature = 'rag:rabbit-ingestion-worker
        {--once : Process one message and exit}
        {--timeout=5 : RabbitMQ wait timeout in seconds}';

    protected $description = 'Consume converted-document RabbitMQ events in Laravel and call the Python RAG bridge.';

    private bool $shouldStop = false;
    private int $lastExitCode = PipelineExitCode::SUCCESS;

    public function handle(RagRabbitMQ $rabbit, ConvertedDocumentIngestionService $ingestion): int
    {
        $cfg = config('communication.rabbitmq.rag_ingestion');
        $rabbit->declareRagIngestionTopology();
        $channel = $rabbit->channel();
        $channel->basic_qos(0, (int) $cfg['prefetch_count'], false);

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        }

        $processed = 0;
        $channel->basic_consume(
            (string) $cfg['queue'],
            (string) $cfg['consumer_tag'],
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($rabbit, $ingestion, $cfg, &$processed): void {
                $this->lastExitCode = $this->processMessage($message, $rabbit, $ingestion, $cfg);
                $processed++;
                if ($this->option('once')) {
                    $this->shouldStop = true;
                }
            },
        );

        $this->info("Laravel RAG ingestion worker listening on {$cfg['queue']}.");

        try {
            while (!$this->shouldStop && $channel->is_consuming()) {
                try {
                    $channel->wait(null, false, (int) $this->option('timeout'));
                } catch (AMQPTimeoutException) {
                    if ($this->option('once') && $processed === 0) {
                        $this->line('No message received before timeout.');
                        $this->lastExitCode = PipelineExitCode::PARTIAL_SUCCESS;
                        break;
                    }
                }
            }
        } finally {
            $rabbit->close();
        }

        return $this->option('once') ? $this->lastExitCode : PipelineExitCode::SUCCESS;
    }

    private function processMessage(AMQPMessage $message, RagRabbitMQ $rabbit, ConvertedDocumentIngestionService $ingestion, array $cfg): int
    {
        $retryCount = 0;
        $maxRetries = (int) $cfg['max_retries'];
        $event = [];

        try {
            $event = $this->decodeEvent($message->getBody());
            $retryCount = max(0, (int) ($event['retry_count'] ?? 0));
            $maxRetries = max(0, (int) ($event['max_retries'] ?? $maxRetries));

            $state = $ingestion->claim($event, $retryCount, $maxRetries);
            if ($state === null) {
                $this->line("Skipping already completed RAG job {$event['job_id']}.");
                $message->ack();
                return PipelineExitCode::SUCCESS;
            }

            $ingestion->ingest($event);
            $ingestion->markCompleted($event, $retryCount, $maxRetries);
            $message->ack();
            $this->info("Completed RAG job {$event['job_id']}.");
            return PipelineExitCode::SUCCESS;
        } catch (Throwable $error) {
            return $this->handleFailure($message, $rabbit, $ingestion, $event, $retryCount, $maxRetries, $error);
        }
    }

    private function handleFailure(
        AMQPMessage $message,
        RagRabbitMQ $rabbit,
        ConvertedDocumentIngestionService $ingestion,
        array $event,
        int $retryCount,
        int $maxRetries,
        Throwable $error,
    ): int {
        $jobId = (string) ($event['job_id'] ?? 'unknown');
        Log::warning('RAG ingestion RabbitMQ job failed', [
            'job_id' => $jobId,
            'retry_count' => $retryCount,
            'max_retries' => $maxRetries,
            'error' => $error->getMessage(),
        ]);

        $nextRetry = $retryCount + 1;
        if (!$ingestion->isPermanent($error) && $nextRetry <= $maxRetries && $event !== []) {
            $retryPayload = $event;
            $retryPayload['retry_count'] = $nextRetry;
            $retryPayload['max_retries'] = $maxRetries;
            $retryPayload['last_error_type'] = class_basename($error);
            $retryPayload['last_error_message'] = $error->getMessage();
            $rabbit->publishRetry($retryPayload);
            $ingestion->markReceivedForRetry($event, $nextRetry, $maxRetries, $error);
            $message->ack();
            $this->warn("Retry {$nextRetry}/{$maxRetries} published for RAG job {$jobId}.");
            return PipelineExitCode::RUNTIME_FAILURE;
        }

        $failedRetryCount = $ingestion->isPermanent($error) ? $retryCount : $nextRetry;
        $failedPayload = $ingestion->failedEvent($event, $failedRetryCount, $maxRetries, $error);
        $rabbit->publishFailed($failedPayload);

        if ($event !== []) {
            $ingestion->markFailed($event, $failedRetryCount, $maxRetries, $error);
        }

        $message->ack();
        $this->error("Failed RAG job {$jobId}; published pipeline.failed.");
        return PipelineExitCode::RUNTIME_FAILURE;
    }

    /**
     * @throws JsonException
     */
    private function decodeEvent(string $body): array
    {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new JsonException('RabbitMQ message payload must be a JSON object.');
        }

        return $decoded;
    }
}
