<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pipeline\PipelineArchitectureService;
use Illuminate\Console\Command;

class PipelineArchitectureCommand extends Command
{
    protected $signature = 'pipeline:architecture';

    protected $description = 'Print pipeline event contracts, RabbitMQ topology, flow, and failure modes.';

    public function handle(PipelineArchitectureService $architecture): int
    {
        $summary = $architecture->summary();

        $this->line('Pipeline event contracts');
        $this->table(
            ['Event', 'Job type', 'Consumed by', 'Next events'],
            array_map(static fn (array $event): array => [
                $event['eventType'],
                $event['jobType'] ?? '-',
                implode(', ', $event['consumedBy']) ?: '-',
                implode(', ', $event['typicalNextEvents']) ?: '-',
            ], $summary['events']),
        );

        $topology = $summary['topology'];
        $this->newLine();
        $this->line('RabbitMQ topology');
        $this->line('events exchange: ' . $topology['eventsExchange']);
        $this->line('retry exchange: ' . $topology['retryExchange']);
        $this->line('failed exchange: ' . $topology['failedExchange']);
        $this->line('retry delay ms: ' . $topology['retryDelayMs']);
        $this->line('max retries: ' . $topology['maxRetries']);

        $this->newLine();
        $this->line('Failure modes');
        $this->table(
            ['Mode', 'Owner', 'Expected recovery'],
            array_map(static fn (array $failure): array => [
                $failure['mode'],
                $failure['owner'],
                $failure['expectedRecovery'],
            ], $summary['failureModes']),
        );

        $this->newLine();
        $this->line('Handler responsibilities');
        $this->table(
            ['Handler', 'Consumes', 'Publishes'],
            array_map(static fn (array $handler): array => [
                $handler['handler'],
                implode(', ', $handler['consumes']),
                implode(', ', $handler['publishes']),
            ], $summary['handlers']),
        );

        $this->newLine();
        $this->line('Persistence map');
        $this->table(
            ['Table', 'Repository', 'Purpose'],
            array_map(static fn (array $record): array => [
                $record['table'],
                $record['repository'],
                $record['purpose'],
            ], $summary['persistence']),
        );

        $this->newLine();
        $this->line('Recovery');
        $this->line($summary['recovery']['principle']);

        $this->newLine();
        $this->line('Mental model');
        foreach ($summary['mentalModel'] as $index => $step) {
            $this->line(($index + 1) . '. ' . $step);
        }

        return self::SUCCESS;
    }
}
