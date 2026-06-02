<?php

namespace App\Console\Commands\Pipeline;

use App\Services\Pipeline\EventHandlers\ConverterEventHandler;
use App\Services\Pipeline\PipelineEventWorker;
use Illuminate\Console\Command;

class ConverterEventWorkerCommand extends Command
{
    protected $signature = 'pipeline:converter-event-worker
        {--once : Process one message and exit}
        {--timeout=5 : RabbitMQ wait timeout in seconds}';

    protected $description = 'Consume file conversion events and publish converted-file events.';

    public function handle(PipelineEventWorker $worker, ConverterEventHandler $handler): int
    {
        return $worker->run($this, 'converter', $handler);
    }
}
