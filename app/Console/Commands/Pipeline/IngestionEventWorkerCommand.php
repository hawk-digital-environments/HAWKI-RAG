<?php

namespace App\Console\Commands\Pipeline;

use App\Services\Pipeline\EventHandlers\IngestionEventHandler;
use App\Services\Pipeline\PipelineEventWorker;
use Illuminate\Console\Command;

class IngestionEventWorkerCommand extends Command
{
    protected $signature = 'pipeline:ingestion-event-worker
        {--once : Process one message and exit}
        {--timeout=5 : RabbitMQ wait timeout in seconds}';

    protected $description = 'Consume scraped/converted content events and ingest them into RAG storage.';

    public function handle(PipelineEventWorker $worker, IngestionEventHandler $handler): int
    {
        return $worker->run($this, 'ingestion', $handler);
    }
}
