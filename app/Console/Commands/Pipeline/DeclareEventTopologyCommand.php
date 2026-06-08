<?php

namespace App\Console\Commands\Pipeline;

use App\Services\Pipeline\PipelineEventBus;
use Illuminate\Console\Command;

class DeclareEventTopologyCommand extends Command
{
    protected $signature = 'pipeline:declare-event-topology
        {worker? : Optional worker key to declare, for example scrape_monitor}';

    protected $description = 'Declare RabbitMQ queues and bindings for pipeline event workers without consuming messages.';

    public function handle(PipelineEventBus $events): int
    {
        $worker = $this->argument('worker');
        $configuredWorkers = (array) config('communication.rabbitmq.pipeline_events.workers', []);
        $workers = is_string($worker) && trim($worker) !== ''
            ? [trim($worker)]
            : array_keys($configuredWorkers);

        foreach ($workers as $name) {
            if (!array_key_exists((string) $name, $configuredWorkers)) {
                $this->error("Unknown pipeline event worker: {$name}");

                return self::FAILURE;
            }

            $topology = $events->declareWorkerTopology((string) $name);
            $this->info(sprintf(
                'Declared %s: queue %s listens to %s.',
                $name,
                $topology['queue'],
                implode(', ', $topology['listen']),
            ));
        }

        $failed = $events->declareFailedTopology();
        $this->info(sprintf(
            'Declared failed queue %s for %s.',
            $failed['queue'],
            $failed['routing_key'],
        ));

        return self::SUCCESS;
    }
}
