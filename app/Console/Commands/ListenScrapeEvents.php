<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class ListenScrapeEvents extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'listen:scrape-events';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Subscribe to scrape-events channel on Redis';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Listening on scrape-events...');
        Redis::connection()->subscribe(['scrape-events'], function ($message) {
            $this->info("Received: $message");
        });
    }
}
