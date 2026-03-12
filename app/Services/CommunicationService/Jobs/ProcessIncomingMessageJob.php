<?php

namespace App\Services\CommunicationService\Jobs;

use App\Events\ScrapeEvent;
use App\Services\CommunicationService\Data\IncomingMessage;
use App\Services\ScrapeService\Data\ScrapeEventPacket;
use App\Services\ScrapeService\Pipeline\ScrapeContextBuilder;
use App\Services\ScrapeService\Pipeline\ScrapeEventHandler;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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
     * @throws Exception
     */
    public function handle(): void
    {
        try {
//            Log::debug("Processing incoming message from {$this->message->source}", [
//                'channel' => $this->message->channel,
//                'payload_preview' => substr($this->message->rawPayload, 0, 200)
//            ]);

            switch ($this->message->channel){
                case ('scrape-events'):
                    $handler = new ScrapeEventHandler();
                    $handler->handle($this->message->decodedPayload);
                break;
            }
        } catch (Exception $e) {
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
        Log::error("ProcessIncomingMessageJob failed permanently", [
            'exception' => $exception->getMessage(),
            'message' => $this->message->toArray(),
            'attempts' => $this->attempts()
        ]);
    }
}
