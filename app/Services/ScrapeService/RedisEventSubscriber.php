<?php

namespace App\Services\ScrapeService;

use App\Events\ScrapeEvent;
use App\Models\ScrapeMetadata;
use App\Models\ScrapeProcess;
use App\Services\ScrapeService\Data\ScrapeEventPacket;
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

    public function __construct(?string $channel = null)
    {
        $this->channel = $channel ?? config('scrape.redis_channel', 'scrape-events');
    }

    /**
     * Start subscribing to the Redis channel.
     * This method will block and run indefinitely until stopped.
     *
     * @return void
     */
    public function subscribe(): void
    {
        Log::info("Starting Redis subscriber on channel: {$this->channel}");

        while (!$this->shouldStop) {
            try {
                $this->reconnectAttempts = 0;

                Redis::subscribe([$this->channel], function (string $message, string $channel) {
                    $this->handleMessage($message, $channel);
                });

            } catch (\Exception $e) {
                $this->handleSubscriptionError($e);
            }
        }

        Log::info("Redis subscriber stopped");
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
        $this->handleEventType($process, $packet);
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
     * Handle event-specific logic.
     *
     * @param ScrapeProcess $process
     * @param ScrapeEventPacket $packet
     * @return void
     */
    protected function handleEventType(ScrapeProcess $process, ScrapeEventPacket $packet): void
    {
        match ($packet->event) {
            'sitemap_detected' => $this->handleSitemapDetected($process, $packet),
            'urls_discovered' => $this->handleUrlsDiscovered($process, $packet),
            'crawling_started' => $this->handleCrawlingStarted($process, $packet),
            'url_fetching' => $this->handleUrlFetching($process, $packet),
            'url_scraped' => $this->handleUrlScraped($process, $packet),
            'url_completed' => $this->handleUrlCompleted($process, $packet),
            'job_completed' => $this->handleJobCompleted($process, $packet),
            default => Log::debug("No specific handler for event: {$packet->event}")
        };
    }

    // Event-specific handlers

    protected function handleSitemapDetected(ScrapeProcess $process, ScrapeEventPacket $packet): void
    {
        Log::info("Sitemap detected", [
            'job_id' => $packet->jobId,
            'sitemap_url' => $packet->data['sitemap_url'] ?? null,
            'total_urls' => $packet->data['total_urls'] ?? 0
        ]);
    }

    protected function handleUrlsDiscovered(ScrapeProcess $process, ScrapeEventPacket $packet): void
    {
        Log::info("URLs discovered", [
            'job_id' => $packet->jobId,
            'urls_count' => $packet->data['urls_count'] ?? 0,
            'total_to_crawl' => $packet->data['total_to_crawl'] ?? 0
        ]);
    }

    protected function handleCrawlingStarted(ScrapeProcess $process, ScrapeEventPacket $packet): void
    {
        Log::info("Crawling started", [
            'job_id' => $packet->jobId,
            'total_pages' => $packet->data['total_pages'] ?? 0
        ]);
    }

    protected function handleUrlFetching(ScrapeProcess $process, ScrapeEventPacket $packet): void
    {
        // You might want to throttle these logs or store them differently
        Log::debug("Fetching URL", [
            'job_id' => $packet->jobId,
            'url' => $packet->data['url'] ?? null,
            'page_number' => $packet->data['page_number'] ?? null
        ]);
    }

    protected function handleUrlScraped(ScrapeProcess $process, ScrapeEventPacket $packet): void
    {
        $success = $packet->data['success'] ?? false;
        $level = $success ? 'info' : 'warning';

        Log::log($level, "URL scraped", [
            'job_id' => $packet->jobId,
            'url' => $packet->data['url'] ?? null,
            'success' => $success,
            'error' => $packet->data['error'] ?? null
        ]);
    }

    protected function handleUrlCompleted(ScrapeProcess $process, ScrapeEventPacket $packet): void
    {
        Log::info("URL processing completed", [
            'job_id' => $packet->jobId,
            'url' => $packet->data['url'] ?? null,
            'files_created' => $packet->data['files_created'] ?? []
        ]);
    }

    protected function handleJobCompleted(ScrapeProcess $process, ScrapeEventPacket $packet): void
    {
        $success = $packet->data['success'] ?? false;
        $level = $success ? 'info' : 'error';

        Log::log($level, "Job completed", [
            'job_id' => $packet->jobId,
            'pages_crawled' => $packet->data['pages_crawled'] ?? 0,
            'output_directory' => $packet->data['output_directory'] ?? null,
            'success' => $success,
            'error' => $packet->data['error'] ?? null
        ]);
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
     * Handle subscription errors with reconnection logic.
     *
     * @param \Exception $e
     * @return void
     */
    protected function handleSubscriptionError(\Exception $e): void
    {
        $this->reconnectAttempts++;

        Log::error("Redis subscription error (attempt {$this->reconnectAttempts}/{$this->maxReconnectAttempts}): " . $e->getMessage(), [
            'exception' => $e
        ]);

        if ($this->reconnectAttempts >= $this->maxReconnectAttempts) {
            Log::critical("Max reconnection attempts reached. Stopping subscriber.");
            $this->shouldStop = true;
            return;
        }

        Log::info("Reconnecting in {$this->reconnectDelay} seconds...");
        sleep($this->reconnectDelay);

        // Exponential backoff (optional)
        // $this->reconnectDelay = min($this->reconnectDelay * 2, 60);
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
