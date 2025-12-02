<?php

namespace App\Console\Commands\RedisSubscription;

use App\Services\ScrapeService\ScraperEventSubscriber;
use Illuminate\Console\Command;

class SubscribeToRedisChannel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'redis:subscribe
                            {--channel= : Redis channel to subscribe to (default: scrape-events)}';

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

        // Create the subscriber instance
        $subscriber = new ScraperEventSubscriber($channel);

        // Set up output callback to display messages in the console
        $subscriber->setOutputCallback(function (string $message, string $type) {
            match ($type) {
                'info' => $this->info($message),
                'error' => $this->error($message),
                'line' => $this->line($message),
                default => $this->line($message),
            };
        });

        // Delegate all subscription logic to the service
        $exitCode = $subscriber->subscribeWithNativeRedis();

        return $exitCode === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
