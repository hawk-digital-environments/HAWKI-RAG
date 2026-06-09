<?php

namespace App\Console\Commands\Pipeline;

use App\Services\Pipeline\EventHandlers\ScrapeMonitorEventHandler;
use App\Services\Pipeline\Events\PipelineEventWorker;
use Illuminate\Console\Command;

class ScrapeMonitorEventWorkerCommand extends Command
{
    protected $signature = 'pipeline:scrape-monitor-event-worker
        {--once : Process one message and exit}
        {--timeout=5 : RabbitMQ wait timeout in seconds}';

    protected $description = 'Consume scrape monitor events and publish scrape completion or failure events.';

    public function handle(PipelineEventWorker $worker, ScrapeMonitorEventHandler $handler): int
    {
        return $worker->run($this, 'scrape_monitor', $handler);
    }
}
