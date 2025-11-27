<?php

namespace App\Services\ScrapeService;

use App\Events\ScrapeEvent;
use App\Models\ScrapeMetadata;
use App\Models\ScrapeProcess;
use App\Services\ScrapeService\Data\ScrapeEventPacket;
use App\Services\ScrapeService\Pipeline\RedisEventHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Redis Event Subscriber - Laravel Side
 * ======================================
 * This service subscribes to Redis Pub/Sub channels and processes
 * scrape events published by the Python microservice.
 *
 * Features:
 * - Automatic reconnection on failure
 * - Event validation and processing
 * - Database persistence (ScrapeMetadata)
 * - Event broadcasting to Laravel application
 * - Error handling and logging
 *
 * Usage:
 * Run as a Laravel command:
 * php artisan scrape:subscribe
 */
class RedisEventSubscriber
{
    protected string $channel;
    protected bool $shouldStop = false;
    protected int $reconnectDelay = 2; // seconds
    protected int $maxReconnectAttempts = 10;
    protected int $reconnectAttempts = 0;
    protected ?\Closure $outputCallback = null;

    protected RedisEventHandler $redisEventHandler;
    public function __construct
    (
        RedisEventHandler $redisEventHandler,
        ?string $channel = null
    )
    {
        $this->redisEventHandler = $redisEventHandler;
        $this->channel = $channel ?? config('scrape.redis_channel', 'scrape-events');
    }

    /**
     * Set output callback for console messages.
     *
     * @param \Closure $callback
     * @return self
     */
    public function setOutputCallback(\Closure $callback): self
    {
        $this->outputCallback = $callback;
        return $this;
    }

    /**
     * Output a message (to console if callback is set, otherwise to log).
     *
     * @param string $message
     * @param string $type 'info'|'error'|'line'
     * @return void
     */
    protected function output(string $message, string $type = 'info'): void
    {
        if ($this->outputCallback) {
            ($this->outputCallback)($message, $type);
        }
    }

    /**
     * Start subscribing to the Redis channel using native Redis extension.
     * This method will block and run indefinitely until stopped.
     * Includes signal handling and reconnection logic.
     *
     * @return int Exit code (0 for success, 1 for failure)
     */
    public function subscribeWithNativeRedis(): int
    {
        $this->output('');
        $this->output("Starting Redis subscriber...", 'info');
        $this->output("Channel: {$this->channel}", 'info');
        $this->output("Press Ctrl+C to stop", 'info');
        $this->output('');

        // Set up signal handling if available
        $this->setupSignalHandling();

        try {
            // Use a persistent connection for pub/sub
            $redis = new \Redis();
            $redis->pconnect('redis', 6379, 0);

            $this->output("Connected to Redis successfully!", 'info');
            $this->output("Subscribing to channel...", 'info');

            Log::info("Starting Redis subscriber on channel: {$this->channel}");

            // Subscribe and process messages using native Redis
            $redis->subscribe([$this->channel], function($redis, $chan, $message) {
                if ($this->shouldStop) {
                    return; // Stop processing
                }

                try {
                    $this->handleMessage($message, $chan);
                } catch (\Exception $e) {
                    Log::error("Error processing message: " . $e->getMessage());
                }
            });

            $this->output('Subscriber stopped gracefully', 'info');
            Log::info("Redis subscriber stopped");
            return 0; // Success

        } catch (\Exception $e) {
            $this->output("Fatal error: " . $e->getMessage(), 'error');
            Log::error("Redis subscriber fatal error: " . $e->getMessage(), [
                'exception' => $e
            ]);

            // Retry after a delay
            sleep(5);
            return 1; // Failure
        }
    }

    /**
     * Set up PCNTL signal handling for graceful shutdown.
     *
     * @return void
     */
    protected function setupSignalHandling(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () {
                $this->shouldStop = true;
            });
            pcntl_signal(SIGINT, function () {
                $this->shouldStop = true;
            });
        }
    }

    /**
     * Handle incoming Redis message.
     *
     * @param string $message JSON-encoded message
     * @param string $channel Redis channel name
     * @return void
     */
    protected function handleMessage(string $message, string $channel): void
    {
        try {
            // Decode JSON message
            $data = json_decode($message, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("Invalid JSON received on channel {$channel}: " . json_last_error_msg());
                return;
            }

            // Validate message structure
            if (!$this->isValidEventPacket($data)) {
                Log::warning("Invalid event packet structure received", ['data' => $data]);
                return;
            }

            // Create ScrapeEventPacket object
            $packet = new ScrapeEventPacket(
                jobId: $data['job_id'],
                event: $data['event'],
                data: $data['data'],
                timestamp: $data['timestamp']
            );

            // Process the event
            $this->processEvent($packet);

        } catch (\Exception $e) {
            Log::error("Error processing Redis message: " . $e->getMessage(), [
                'exception' => $e,
                'message' => $message
            ]);
        }
    }

    /**
     * Process a validated event packet.
     *
     * @param ScrapeEventPacket $packet
     * @return void
     */
    protected function processEvent(ScrapeEventPacket $packet): void
    {
        Log::info("Processing event: {$packet->event} for job {$packet->jobId}", [
            'job_id' => $packet->jobId,
            'event' => $packet->event,
            'data' => $packet->data
        ]);

        // Find the ScrapeProcess record
        $process = ScrapeProcess::where('job_id', $packet->jobId)->first();

        if (!$process) {
            Log::warning("ScrapeProcess not found for job_id: {$packet->jobId}");
            // You might want to create it here or handle this case differently
            return;
        }

        // Store event in metadata table
        $this->storeEventMetadata($process, $packet);

        // Update process status based on event type
        $this->updateProcessStatus($process, $packet);

        // Dispatch Laravel event (optional - for broadcasting to frontend)
        event(new ScrapeEvent());

        // Call event-specific handlers
        $this->redisEventHandler->handleEventType($process, $packet);
    }

    /**
     * Store event metadata in database.
     *
     * @param ScrapeProcess $process
     * @param ScrapeEventPacket $packet
     * @return void
     */
    protected function storeEventMetadata(ScrapeProcess $process, ScrapeEventPacket $packet): void
    {
        try {
            ScrapeMetadata::create([
                'scrape_job_id' => $process->id,
                'event' => $packet->event,
                'data' => $packet->data,
                'timestamp' => $packet->timestamp
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to store event metadata: " . $e->getMessage(), [
                'job_id' => $packet->jobId,
                'event' => $packet->event
            ]);
        }
    }

    /**
     * Update process status based on event.
     *
     * @param ScrapeProcess $process
     * @param ScrapeEventPacket $packet
     * @return void
     */
    protected function updateProcessStatus(ScrapeProcess $process, ScrapeEventPacket $packet): void
    {
        $statusMap = [
            'crawling_started' => 'running',
            'job_completed' => $packet->data['success'] ?? true ? 'completed' : 'failed',
            'url_scraped' => 'running',
            'url_fetching' => 'running',
        ];

        if (isset($statusMap[$packet->event])) {
            $newStatus = $statusMap[$packet->event];

            $process->update([
                'status' => $newStatus,
                'ended_at' => $packet->event === 'job_completed' ? now() : null
            ]);

            Log::debug("Updated process status to: {$newStatus}", [
                'job_id' => $packet->jobId,
                'event' => $packet->event
            ]);
        }
    }

    /**
     * Validate event packet structure.
     *
     * @param array $data
     * @return bool
     */
    protected function isValidEventPacket(array $data): bool
    {
        return isset($data['job_id']) &&
               isset($data['event']) &&
               isset($data['data']) &&
               isset($data['timestamp']) &&
               is_string($data['job_id']) &&
               is_string($data['event']) &&
               is_array($data['data']) &&
               is_string($data['timestamp']);
    }

    /**
     * Stop the subscriber gracefully.
     *
     * @return void
     */
    public function stop(): void
    {
        $this->shouldStop = true;
        Log::info("Stop signal received for Redis subscriber");
    }
}
