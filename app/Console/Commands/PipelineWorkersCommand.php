<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PipelineWorkersCommand extends Command
{
    protected $signature = 'pipeline:workers';

    protected $description = 'Print MVP pipeline worker startup commands and RabbitMQ queue topology.';

    public function handle(): int
    {
        $workers = config('communication.rabbitmq.pipeline_events.workers', []);

        $this->line('HAWKI RAG MVP pipeline workers');
        $this->line('Laravel owns orchestration. RabbitMQ is the event bus. No Prefect. No Redis.');
        $this->newLine();

        $this->line('Start all pipeline workers with Docker:');
        $this->line('  docker compose --profile pipeline-events up -d hawki-rag-scraper-event-worker hawki-rag-scrape-monitor-event-worker hawki-rag-converter-event-worker hawki-rag-ingestion-event-worker');
        $this->newLine();

        $this->line('Direct Artisan commands, one terminal per command:');
        $this->line('  php artisan pipeline:scraper-event-worker');
        $this->line('  php artisan pipeline:scrape-monitor-event-worker');
        $this->line('  php artisan pipeline:converter-event-worker');
        $this->line('  php artisan pipeline:ingestion-event-worker');
        $this->newLine();

        $this->line('Scrape monitoring: RabbitMQ owns Crawl4AI status polling through scrape.monitor.requested events.');
        $this->newLine();

        $this->line('RabbitMQ exchanges:');
        $this->line('  events exchange: ' . config('communication.rabbitmq.pipeline_events.exchange'));
        $this->line('  retry exchange: ' . config('communication.rabbitmq.pipeline_events.retry_exchange'));
        $this->line('  failed exchange: ' . config('communication.rabbitmq.pipeline_events.failed_exchange'));
        $this->line('  retry delay ms: ' . config('communication.rabbitmq.pipeline_events.retry_delay_ms'));
        $this->line('  max retries: ' . config('communication.rabbitmq.pipeline_events.max_retries'));
        $this->newLine();

        $this->table(
            ['Worker', 'Command', 'Queue', 'Listens to', 'Retry queues', 'Consumer tag'],
            $this->workerRows(is_array($workers) ? $workers : []),
        );

        $this->newLine();
        $this->line('Failed event queue:');
        $this->line('  queue: ' . config('communication.rabbitmq.pipeline_events.failed_queue', 'pipeline_failed_events'));
        $this->line('  routing key: ' . config('communication.rabbitmq.pipeline_events.failed_routing_key', 'job.failed'));
        $this->line('  event: job.failed');

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
            ->map(function (array $config, string $worker) use ($commands): array {
                $queue = (string) ($config['queue'] ?? '');
                $events = array_values(array_filter(array_map('strval', $config['listen'] ?? [])));

                return [
                    $worker,
                    $commands[$worker] ?? 'unknown',
                    $queue,
                    implode(', ', $events),
                    implode(', ', array_map(fn (string $event): string => $this->retryQueueName($queue, $event), $events)),
                    (string) ($config['consumer_tag'] ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    private function retryQueueName(string $workerQueue, string $eventType): string
    {
        return $workerQueue . '.retry.' . str_replace(['.', ':'], '_', $eventType);
    }
}
