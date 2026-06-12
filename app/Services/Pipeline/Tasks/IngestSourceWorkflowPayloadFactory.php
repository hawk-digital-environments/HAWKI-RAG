<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class IngestSourceWorkflowPayloadFactory
{
    public function __construct(
        private ConfigRepository $config,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function input(PipelineTask $task, PipelineJob $job, IngestionSource $source): array
    {
        $metadata = $source->metadata ?? [];
        $refresh = is_array($metadata['refresh'] ?? null) ? $metadata['refresh'] : [];

        return [
            'source_id' => $source->source_id,
            'source_url' => $source->source_url,
            'task_id' => $task->task_id,
            'job_id' => $job->job_id,
            'dataset_id' => $task->dataset_id,
            'refresh' => array_merge([
                'cadence' => $source->refresh_cadence,
                'requested_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
                'etag' => $source->etag,
                'last_modified' => $source->last_modified,
                'content_hash' => $source->content_hash,
                'document_version' => $source->document_version,
            ], $refresh),
            'raw_output_path' => $source->raw_storage_path,
            'markdown_output_path' => $source->markdown_storage_path,
            'ingest_manifest_path' => $this->manifestPath($source->source_id),
            'storage' => [
                'mode' => $this->storageMode(),
                'shared_root' => $this->sharedRoot(),
                'object_prefix' => $this->objectPrefix(),
            ],
            'task_queues' => [
                'workflow' => $this->config->get('temporal.task_queues.workflow', 'rag-workflow-task-queue'),
                'scraper' => $this->config->get('temporal.task_queues.scraper', 'rag-scraper-task-queue'),
                'converter' => $this->config->get('temporal.task_queues.converter', 'rag-converter-task-queue'),
                'ingestion' => $this->config->get('temporal.task_queues.ingestion', 'rag-ingestion-task-queue'),
            ],
            'ingestion' => [
                'provider' => $this->config->get('temporal.ingestion.provider', 'ollama'),
                'graph' => filter_var($this->config->get('temporal.ingestion.graph', false), FILTER_VALIDATE_BOOLEAN),
                'collection' => $metadata['dataset']['qdrant_collection'] ?? null,
                'neo4j_namespace' => $metadata['dataset']['neo4j_namespace'] ?? null,
                'chunk_chars' => (int) $this->config->get('config.chunk_size', env('CHUNK_SIZE', 1200)),
                'chunk_overlap' => (int) $this->config->get('config.chunk_overlap_size', env('CHUNK_OVERLAP_SIZE', 250)),
                'batch_size' => (int) $this->config->get('config.ingest_batch_size', 64),
            ],
            'external_services' => $this->config->get('temporal.external_services', []),
        ];
    }

    public function sourceId(string $datasetId, string $url): string
    {
        return 'source_'.substr(hash('sha256', $datasetId.'|'.$url), 0, 32);
    }

    public function workflowId(string $sourceId): string
    {
        return 'ingest-source-'.$sourceId;
    }

    public function scheduleId(string $sourceId): string
    {
        return 'schedule-ingest-source-'.$sourceId;
    }

    /**
     * @return array{raw: string, markdown: string}
     */
    public function storagePaths(string $sourceId): array
    {
        $base = $this->storageMode() === 'object'
            ? rtrim($this->objectPrefix(), '/').'/sources/'.$sourceId
            : rtrim($this->sharedRoot(), '/').'/sources/'.$sourceId;

        return [
            'raw' => $base.'/raw/',
            'markdown' => $base.'/markdown/',
        ];
    }

    private function manifestPath(string $sourceId): string
    {
        $base = $this->storageMode() === 'object'
            ? rtrim($this->objectPrefix(), '/').'/sources/'.$sourceId
            : rtrim($this->sharedRoot(), '/').'/sources/'.$sourceId;

        return $base.'/ingest/manifest.json';
    }

    private function storageMode(): string
    {
        return (string) $this->config->get('temporal.storage.mode', 'shared');
    }

    private function sharedRoot(): string
    {
        return (string) $this->config->get('temporal.storage.shared_root', '/shared');
    }

    private function objectPrefix(): string
    {
        return (string) $this->config->get('temporal.storage.object_prefix', 's3://hawki-rag');
    }
}
