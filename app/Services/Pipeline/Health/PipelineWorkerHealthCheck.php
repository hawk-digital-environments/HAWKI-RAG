<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Health;

use App\Console\Commands\Pipeline\ConverterEventWorkerCommand;
use App\Console\Commands\Pipeline\IngestionEventWorkerCommand;
use App\Console\Commands\Pipeline\ScrapeMonitorEventWorkerCommand;
use App\Console\Commands\Pipeline\ScraperEventWorkerCommand;
use App\Services\Pipeline\Events\PipelineEventConfig;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class PipelineWorkerHealthCheck
{
    public function __construct(
        private ConfigRepository $config,
        private HttpEndpointHealthCheck $httpChecks,
        private PipelineEventConfig $events,
        private PipelineHealthResultFactory $results,
    ) {
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function scraper(int $timeout): array
    {
        $worker = $this->workerConfig('scraper');
        $url = rtrim((string) $this->config->get('scraper.api_url'), '/').'/health';
        $configError = $this->workerConfigError(ScraperEventWorkerCommand::class, $worker, 'scraper');
        if ($configError !== null) {
            return $configError;
        }

        return $this->httpChecks->reachabilityCheck(
            'Scraper worker',
            $url,
            $timeout,
            sprintf('Worker command registered, queue %s listens to %s.', $worker['queue'], implode(', ', $worker['listen'])),
            'Start the scraper service or set CUSTOM_CRAWLER_URL. Start the consumer with php artisan pipeline:scraper-event-worker.',
        );
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function scrapeMonitor(): array
    {
        $worker = $this->workerConfig('scrape_monitor');
        $configError = $this->workerConfigError(ScrapeMonitorEventWorkerCommand::class, $worker, 'scrape_monitor');
        if ($configError !== null) {
            return $configError;
        }

        return $this->results->ok(
            'Scrape monitor worker',
            sprintf(
                'Worker command registered, queue %s listens to %s. RabbitMQ owns Crawl4AI completion polling.',
                $worker['queue'],
                implode(', ', $worker['listen']),
            ),
        );
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function converter(int $timeout): array
    {
        $worker = $this->workerConfig('converter');
        $url = (string) $this->config->get('file_converter.health_url');
        $configError = $this->workerConfigError(ConverterEventWorkerCommand::class, $worker, 'converter');
        if ($configError !== null) {
            return $configError;
        }

        if (trim($url) === '') {
            return $this->results->failure(
                'Converter worker',
                'FILE_CONVERTER_HEALTH_URL is empty.',
                'Set FILE_CONVERTER_URL or FILE_CONVERTER_HEALTH_URL and start php artisan pipeline:converter-event-worker.',
            );
        }

        return $this->httpChecks->successCheck(
            'Converter worker',
            $url,
            $timeout,
            sprintf('Worker command registered, queue %s listens to %s.', $worker['queue'], implode(', ', $worker['listen'])),
            'Start the file converter service or set FILE_CONVERTER_URL. Start the consumer with php artisan pipeline:converter-event-worker.',
        );
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function ingestion(int $timeout): array
    {
        $worker = $this->workerConfig('ingestion');
        $bridge = rtrim((string) $this->config->get('config.hawki_rag_bridge_url'), '/');
        $configError = $this->workerConfigError(IngestionEventWorkerCommand::class, $worker, 'ingestion');
        if ($configError !== null) {
            return $configError;
        }

        if ($bridge === '') {
            return $this->results->failure(
                'Ingestion worker',
                'HAWKI_RAG_BRIDGE_URL is empty.',
                'Set HAWKI_RAG_BRIDGE_URL and start php artisan pipeline:ingestion-event-worker.',
            );
        }

        return $this->httpChecks->successCheck(
            'Ingestion worker',
            $bridge.'/health',
            $timeout,
            sprintf(
                'Worker command registered, queue %s listens to %s. Provider: %s, graph: %s.',
                $worker['queue'],
                implode(', ', $worker['listen']),
                $this->config->get('communication.rabbitmq.pipeline_ingestion.provider'),
                filter_var($this->config->get('communication.rabbitmq.pipeline_ingestion.graph'), FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            ),
            'Start hawki_rag_bridge or set HAWKI_RAG_BRIDGE_URL. Start the consumer with php artisan pipeline:ingestion-event-worker.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function workerConfig(string $worker): array
    {
        return $this->events->worker($worker) ?? [];
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}|null
     */
    private function workerConfigError(string $commandClass, array $worker, string $name): ?array
    {
        $displayName = ucfirst(str_replace('_', ' ', $name)).' worker';

        if (! class_exists($commandClass)) {
            return $this->results->failure(
                $displayName,
                "Command class {$commandClass} is missing.",
                'Restore the MVP pipeline worker command class.',
            );
        }

        if (($worker['queue'] ?? '') === '' || ! is_array($worker['listen'] ?? null) || $worker['listen'] === []) {
            return $this->results->failure(
                $displayName,
                'RabbitMQ worker queue or listen events are not configured.',
                "Set communication.rabbitmq.pipeline_events.workers.{$name}.queue and listen events.",
            );
        }

        return null;
    }
}
