<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Health;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class PipelineWorkerHealthCheck
{
    public function __construct(
        private ConfigRepository $config,
        private HttpEndpointHealthCheck $httpChecks,
        private PipelineHealthResultFactory $results,
    ) {}

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function scraper(int $timeout): array
    {
        $scraperUrl = trim((string) $this->config->get('temporal.external_services.scraper_url'));
        $taskQueue = (string) $this->config->get('temporal.task_queues.scraper', 'rag-scraper-task-queue');

        // Skip the HTTP probe when no scraper URL is configured instead of reporting a false failure.
        if ($scraperUrl === '') {
            return $this->results->ok(
                'Scraper adapter worker',
                'No external scraper configured (EXTERNAL_SCRAPER_URL / CUSTOM_CRAWLER_URL not set). Web crawling is disabled; direct-text and file ingestion are unaffected.',
            );
        }

        $url = rtrim($scraperUrl, '/').'/health';

        return $this->httpChecks->reachabilityCheck(
            'Scraper adapter worker',
            $url,
            $timeout,
            sprintf('Temporal activity task queue %s calls the external scraper service.', $taskQueue),
            'Start hawki-rag-temporal-scraper-worker and verify EXTERNAL_SCRAPER_URL or CUSTOM_CRAWLER_URL.',
        );
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function workflow(): array
    {
        return $this->results->ok(
            'Workflow worker',
            sprintf(
                'IngestSourceWorkflow listens on Temporal task queue %s and coordinates scrape, conversion, ingestion, and readiness.',
                $this->config->get('temporal.task_queues.workflow', 'rag-workflow-task-queue'),
            ),
        );
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function converter(int $timeout): array
    {
        $url = (string) $this->config->get('file_converter.health_url');
        $taskQueue = (string) $this->config->get('temporal.task_queues.converter', 'rag-converter-task-queue');

        // FILE_CONVERTER_HEALTH_URL may be empty; fall back to base URL + /health.
        // Without this second guard, an empty base URL still produced '/health' and fired a request.
        if (trim($url) === '') {
            $converterBase = trim((string) $this->config->get('temporal.external_services.converter_url'));
            $url = $converterBase !== '' ? rtrim($converterBase, '/').'/health' : '';
        }

        // Skip the HTTP probe when no converter URL is configured instead of reporting a false failure.
        if (trim($url) === '') {
            return $this->results->ok(
                'Converter adapter worker',
                'No external converter configured (EXTERNAL_CONVERTER_URL / FILE_CONVERTER_HEALTH_URL not set). File conversion is disabled; direct-text ingestion is unaffected.',
            );
        }

        return $this->httpChecks->successCheck(
            'Converter adapter worker',
            $url,
            $timeout,
            sprintf('Temporal activity task queue %s calls the external converter service.', $taskQueue),
            'Start the external converter service and hawki-rag-temporal-converter-worker.',
        );
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function ingestion(int $timeout): array
    {
        $taskQueue = (string) $this->config->get(
            'temporal.task_queues.indexer',
            $this->config->get('temporal.task_queues.ingestion', 'rag-ingestion-task-queue'),
        );
        $legacyQueue = (string) $this->config->get(
            'temporal.task_queues.ingestion',
            'rag-ingestion-task-queue',
        );

        return $this->results->ok(
            'Indexer worker',
            sprintf(
                'Temporal task queue %s indexes artifacts directly into Qdrant/Neo4j; legacy queue %s remains polled while executions drain. Provider: %s, graph: %s.',
                $taskQueue,
                $legacyQueue,
                $this->config->get('temporal.ingestion.provider'),
                filter_var($this->config->get('temporal.ingestion.graph'), FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            ),
        );
    }
}
