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
    ) {
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function scraper(int $timeout): array
    {
        $url = rtrim((string) $this->config->get('temporal.external_services.scraper_url'), '/').'/health';
        $taskQueue = (string) $this->config->get('temporal.task_queues.scraper', 'rag-scraper-task-queue');

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

        if (trim($url) === '') {
            $url = rtrim((string) $this->config->get('temporal.external_services.converter_url'), '/').'/health';
        }

        if (trim($url) === '') {
            return $this->results->failure(
                'Converter adapter worker',
                'Converter health URL is empty.',
                'Set EXTERNAL_CONVERTER_URL or FILE_CONVERTER_HEALTH_URL and start hawki-rag-temporal-converter-worker.',
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
        $bridge = rtrim((string) $this->config->get('config.hawki_rag_bridge_url'), '/');
        $taskQueue = (string) $this->config->get('temporal.task_queues.ingestion', 'rag-ingestion-task-queue');

        if ($bridge === '') {
            return $this->results->failure(
                'Ingestion adapter worker',
                'HAWKI_RAG_BRIDGE_URL is empty.',
                'Set HAWKI_RAG_BRIDGE_URL and start hawki-rag-temporal-ingestion-worker.',
            );
        }

        return $this->httpChecks->successCheck(
            'Ingestion adapter worker',
            $bridge.'/health',
            $timeout,
            sprintf(
                'Temporal activity task queue %s reads Markdown and writes through the RAG bridge. Provider: %s, graph: %s.',
                $taskQueue,
                $this->config->get('temporal.ingestion.provider'),
                filter_var($this->config->get('temporal.ingestion.graph'), FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            ),
            'Start hawki_rag_bridge and hawki-rag-temporal-ingestion-worker, then verify HAWKI_RAG_BRIDGE_URL.',
        );
    }
}
