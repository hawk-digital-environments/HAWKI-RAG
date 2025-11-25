<?php

namespace App\Jobs;

use App\Models\ScrapeMetadata;
use App\Models\ScrapeProcess;
use App\Services\ScrapeService\Data\ScrapeEventPacket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ProcessScrapeEvents implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 3600; // 1 hour

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1;

    protected string $jobId;
    protected string $channel;
    protected int $maxWaitSeconds;

    /**
     * Create a new job instance.
     */
    public function __construct(
        string $jobId,
        ?string $channel = null,
        int $maxWaitSeconds = 3600
    ) {
        $this->jobId = $jobId;
        $this->channel = $channel ?? config('scrape.redis_channel', 'scrape-events');
        $this->maxWaitSeconds = $maxWaitSeconds;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info("Starting Redis event listener for job {$this->jobId}");

        $startTime = time();
        $shouldStop = false;

        try {
            Redis::subscribe([$this->channel], function (string $message) use (&$shouldStop, $startTime) {
                try {
                    // Check timeout
                    if (time() - $startTime > $this->maxWaitSeconds) {
                        Log::warning("Timeout reached for job {$this->jobId} event listener");
                        $shouldStop = true;
                        return;
                    }

                    // Decode message
                    $data = json_decode($message, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        Log::error("Invalid JSON received: " . json_last_error_msg());
                        return;
                    }

                    // Validate event packet structure
                    if (!$this->isValidEventPacket($data)) {
                        Log::warning("Invalid event packet structure", ['data' => $data]);
                        return;
                    }

                    // Only process events for this specific job
                    if ($data['job_id'] !== $this->jobId) {
                        return; // Ignore events from other jobs
                    }

                    // Create event packet
                    $packet = new ScrapeEventPacket(
                        jobId: $data['job_id'],
                        event: $data['event'],
                        data: $data['data'],
                        timestamp: $data['timestamp']
                    );

                    // Process the event
                    $this->processEvent($packet);

                    // Stop listening if job is completed
                    if ($packet->event === 'job_completed') {
                        Log::info("Job {$this->jobId} completed, stopping event listener");
                        $shouldStop = true;
                    }

                } catch (\Exception $e) {
                    Log::error("Error processing event: " . $e->getMessage(), [
                        'exception' => $e,
                        'job_id' => $this->jobId
                    ]);
                }
            });

        } catch (\Exception $e) {
            Log::error("Redis subscription error for job {$this->jobId}: " . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }

    /**
     * Process a validated event packet.
     */
    protected function processEvent(ScrapeEventPacket $packet): void
    {
        Log::info("Processing event: {$packet->event} for job {$packet->jobId}", [
            'event' => $packet->event,
            'data' => $packet->data
        ]);

        // Find the ScrapeProcess record
        $process = ScrapeProcess::where('job_id', $packet->jobId)->first();

        if (!$process) {
            Log::warning("ScrapeProcess not found for job_id: {$packet->jobId}");
            return;
        }

        // Store event in metadata table
        $this->storeEventMetadata($process, $packet);

        // Update process status based on event type
        $this->updateProcessStatus($process, $packet);

        // Call event-specific handlers
        $this->handleEventType($process, $packet);
    }

    /**
     * Store event metadata in database.
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
}
