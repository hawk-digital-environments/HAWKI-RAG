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
        $this->warn('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->warn('                    DEPRECATION WARNING');
        $this->warn('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line('');
        $this->warn('This command is deprecated. Event listeners now start automatically');
        $this->warn('when you submit a scrape job through the ScraperPipelineService.');
        $this->line('');
        $this->warn('The new approach:');
        $this->line('  1. Submit a scrape job via the pipeline');
        $this->line('  2. Event listener is automatically dispatched as a background job');
        $this->line('  3. Events are processed only for that specific job');
        $this->line('  4. Listener stops automatically when job completes');
        $this->line('');
        $this->warn('To use the new system:');
        $this->line('  - Ensure queue worker is running: php artisan queue:work');
        $this->line('  - Submit jobs through ScraperPipelineService');
        $this->line('');
        $this->warn('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line('');

        if (!$this->confirm('Do you want to continue with the old subscriber anyway?', false)) {
            $this->info('Aborting. Please use the queue worker approach instead.');
            return Command::SUCCESS;
        }

        $channel = $this->option('channel') ?? config('scrape.redis_channel', 'scrape-events');
        $verbose = $this->option('verb');

        $this->line('');
        $this->info("Starting Redis subscriber...");
        $this->info("Channel: {$channel}");
        $this->info("Press Ctrl+C to stop");
        $this->line('');

        try {
            // Set up signal handlers for graceful shutdown
            if (extension_loaded('pcntl')) {
                pcntl_async_signals(true);

                $subscriber = new RedisEventSubscriber($channel);

                pcntl_signal(SIGTERM, function () use ($subscriber) {
                    $this->warn('Received SIGTERM, stopping subscriber...');
                    $subscriber->stop();
                });

                pcntl_signal(SIGINT, function () use ($subscriber) {
                    $this->warn('Received SIGINT, stopping subscriber...');
                    $subscriber->stop();
                });
            } else {
                $subscriber = new RedisEventSubscriber($channel);
                $this->warn('PCNTL extension not loaded. Graceful shutdown may not work properly.');
            }

            // Configure logging based on verbosity
            if ($verbose) {
                Log::info("Redis subscriber started in verbose mode");
            }

            // Start subscribing (this blocks)
            $subscriber->subscribe();

            $this->info('Subscriber stopped gracefully');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Fatal error: " . $e->getMessage());
            Log::error("Redis subscriber fatal error: " . $e->getMessage(), [
                'exception' => $e
            ]);
            return Command::FAILURE;
        }
    }
}
