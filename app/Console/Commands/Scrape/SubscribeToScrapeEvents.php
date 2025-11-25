<?php

namespace App\Console\Commands\Scrape;

use App\Services\ScrapeService\RedisEventSubscriber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SubscribeToScrapeEvents extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scrape:subscribe
                            {--channel= : Redis channel to subscribe to (default: scrape-events)}
                            {--verb : Enable verbose logging}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '[DEPRECATED] Subscribe to Redis Pub/Sub channel - Event listeners now start automatically with each job';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $channel = $this->option('channel') ?? config('scrape.redis_channel', 'scrape-events');
        $verbose = $this->option('verb');

        $this->line('');
        $this->info("Starting Redis subscriber...");
        $this->info("Channel: {$channel}");
        $this->info("Press Ctrl+C to stop");
        $this->line('');

        // Set up signal handling if available
        $shouldStop = false;
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$shouldStop) {
                $shouldStop = true;
            });
            pcntl_signal(SIGINT, function () use (&$shouldStop) {
                $shouldStop = true;
            });
        }

        try {
            // Use a persistent connection for pub/sub
            $redis = new \Redis();
            $redis->pconnect('redis', 6379, 0);

            $this->info("Connected to Redis successfully!");
            $this->info("Subscribing to channel...");

            // Create the subscriber instance to handle events
            $subscriber = new RedisEventSubscriber($channel);

            // Subscribe and process messages using native Redis
            $redis->subscribe([$channel], function($redis, $chan, $message) use (&$shouldStop, $subscriber) {
                if ($shouldStop) {
                    return; // Stop processing
                }

                try {
                    // Delegate to the existing subscriber's message handler
                    // We need to use reflection or make the method public
                    $reflection = new \ReflectionClass($subscriber);
                    $method = $reflection->getMethod('handleMessage');
                    $method->setAccessible(true);
                    $method->invoke($subscriber, $message, $chan);

                } catch (\Exception $e) {
                    Log::error("Error processing message: " . $e->getMessage());
                }
            });

            $this->info('Subscriber stopped gracefully');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Fatal error: " . $e->getMessage());
            Log::error("Redis subscriber fatal error: " . $e->getMessage(), [
                'exception' => $e
            ]);

            // Retry after a delay
            sleep(5);
            return Command::FAILURE;
        }
    }
}
