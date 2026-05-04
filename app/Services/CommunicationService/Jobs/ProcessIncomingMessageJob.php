<?php

namespace App\Services\CommunicationService\Jobs;

use App\Services\CommunicationService\Data\IncomingMessage;
use App\Services\Pipeline\PipelineLogger;
use App\Services\ScrapeService\Pipeline\ScrapeEventHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Process Incoming Message Job
 *
 * This job acts as a buffer layer between the communication service
 * and the business logic. It prevents overloading by queuing messages
 * and processing them asynchronously.
 *
 * Benefits:
 * - Rate limiting and backpressure handling
 * - Automatic retries on failure
 * - Better error isolation
 * - Scalability through queue workers
 */
class ProcessIncomingMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly IncomingMessage $message
    ) {}

    /**
     * Execute the job.
     * Sort Package and send to target service.
     *
     * @return void
     * @throws Throwable
     */
    public function handle(): void
    {
        try {
            PipelineLogger::started('scrape', [
                'job_id' => $this->message->decodedPayload['job_id'] ?? null,
                'pipeline_stage' => 'incoming_message',
                'channel' => $this->message->channel,
                'source' => $this->message->source,
                'event_name' => $this->message->decodedPayload['event'] ?? null,
            ]);

            switch ($this->message->channel){
                case ('scrape-events'):
                    $handler = new ScrapeEventHandler();
                    $handler->handle($this->message->decodedPayload);
                break;
                default:
                    PipelineLogger::skipped('scrape', [
                        'job_id' => $this->message->decodedPayload['job_id'] ?? null,
                        'pipeline_stage' => 'incoming_message',
                        'channel' => $this->message->channel,
                        'reason' => 'Unsupported incoming message channel.',
                    ]);
            }
        } catch (Throwable $e) {
            PipelineLogger::failed('scrape', [
                'job_id' => $this->message->decodedPayload['job_id'] ?? null,
                'pipeline_stage' => 'incoming_message',
                'channel' => $this->message->channel,
                'error_message' => $e->getMessage(),
                'exception' => $e,
            ]);
            $this->fail($e);
            // Re-throw to allow queue retry mechanism
            throw $e;
        }
    }
    /**
     * Handle a job failure.
     *
     * @param Throwable $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        PipelineLogger::failed('scrape', [
            'job_id' => $this->message->decodedPayload['job_id'] ?? null,
            'pipeline_stage' => 'incoming_message',
            'channel' => $this->message->channel,
            'error_message' => $exception->getMessage(),
            'exception' => $exception,
            'attempts' => $this->attempts(),
        ]);
    }
}
