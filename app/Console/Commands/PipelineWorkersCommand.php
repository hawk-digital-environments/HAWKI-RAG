<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pipeline\Architecture\PipelineArchitectureService;
use App\Services\Pipeline\Events\PipelineEvent;
use Illuminate\Console\Command;

class PipelineWorkersCommand extends Command
{
    protected $signature = 'pipeline:workers';

    protected $description = 'Print MVP pipeline worker startup commands and RabbitMQ queue topology.';

    public function handle(PipelineArchitectureService $architecture): int
    {
        $topology = $architecture->topology();
        $workerTopology = $topology['queues']['workers'] ?? [];

        $this->line('HAWKI RAG MVP pipeline workers');
        $this->line('Laravel owns orchestration. RabbitMQ is the event bus. No Prefect. No Redis.');
        $this->newLine();

        $this->line('Start all pipeline workers with Docker:');
        $this->line('  docker compose --profile pipeline-events up -d hawki-rag-scraper-event-worker hawki-rag-scrape-monitor-event-worker hawki-rag-converter-event-worker hawki-rag-ingestion-event-worker');
        $this->newLine();

        $this->line('Direct Artisan commands, one terminal per command:');
        $this->line('  php artisan pipeline:declare-event-topology');
        $this->line('  php artisan pipeline:scraper-event-worker');
        $this->line('  php artisan pipeline:scrape-monitor-event-worker');
        $this->line('  php artisan pipeline:converter-event-worker');
        $this->line('  php artisan pipeline:ingestion-event-worker');
        $this->newLine();

        $this->line('Scrape monitoring: RabbitMQ owns Crawl4AI status polling through scrape.monitor.requested events.');
        $this->newLine();

        $this->line('RabbitMQ exchanges:');
        $this->line('  events exchange: '.$topology['eventsExchange']);
        $this->line('  retry exchange: '.$topology['retryExchange']);
        $this->line('  failed exchange: '.$topology['failedExchange']);
        $this->line('  retry delay ms: '.$topology['retryDelayMs']);
        $this->line('  max retries: '.$topology['maxRetries']);
        $this->newLine();

        $this->table(
            ['Worker', 'Command', 'Queue', 'Listens to', 'Retry queues', 'Consumer tag'],
            $this->workerRows(is_array($workerTopology) ? $workerTopology : []),
        );

        $this->newLine();
        $this->line('Failed event queue:');
        $this->line('  queue: '.($topology['queues']['failedQueue'] ?? 'pipeline_failed_events'));
        $this->line('  routing key: '.($topology['failedRoutingKey'] ?? PipelineEvent::JOB_FAILED));
        $this->line('  event: '.PipelineEvent::JOB_FAILED);

        return self::SUCCESS;
    }

    private function workerRows(array $workers): array
    {
        $commands = [
            'scraper' => 'php artisan pipeline:scraper-event-worker',
            'scrape_monitor' => 'php artisan pipeline:scrape-monitor-event-worker',
            'converter' => 'php artisan pipeline:converter-event-worker',
            'ingestion' => 'php artisan pipeline:ingestion-event-worker',
        ];

        return collect($workers)
            ->map(function (array $workerConfig) use ($commands): array {
                $worker = (string) ($workerConfig['worker'] ?? '');
                $queue = (string) ($workerConfig['queueName'] ?? '');
                $events = array_values(array_filter(array_map('strval', $workerConfig['listen'] ?? [])));
                $retryQueues = array_values(array_filter(array_map('strval', $workerConfig['retryQueues'] ?? [])));

                return [
                    $worker,
                    $commands[$worker] ?? 'unknown',
                    $queue,
                    implode(', ', $events),
                    implode(', ', $retryQueues),
                    (string) config("communication.rabbitmq.pipeline_events.workers.{$worker}.consumer_tag", ''),
                ];
            })
            ->values()
            ->all();
    }
}
