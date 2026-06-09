<?php

namespace App\Console\Commands\Pipeline;

use App\Services\Pipeline\EventHandlers\ScraperEventHandler;
use App\Services\Pipeline\Events\PipelineEventWorker;
use Illuminate\Console\Command;

class ScraperEventWorkerCommand extends Command
{
    protected $signature = 'pipeline:scraper-event-worker
        {--once : Process one message and exit}
        {--timeout=5 : RabbitMQ wait timeout in seconds}';

    protected $description = 'Consume task/page scrape events and submit scraper jobs through Laravel.';

    public function handle(PipelineEventWorker $worker, ScraperEventHandler $handler): int
    {
        return $worker->run($this, 'scraper', $handler);
    }
}
