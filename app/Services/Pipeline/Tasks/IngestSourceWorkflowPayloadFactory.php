<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Settings\SettingsService;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class IngestSourceWorkflowPayloadFactory
{
    public function __construct(
        private ConfigRepository $config,
        private SettingsService $settings,
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
        $heap = is_array($metadata['heap'] ?? null) ? $metadata['heap'] : [];

        $upload = is_array($metadata['upload'] ?? null) ? $metadata['upload'] : null;
        $customConverter = is_array($metadata['custom_converter'] ?? null)
            ? $metadata['custom_converter']
            : null;
        $modelRuntime = $this->settings->modelRuntime();

        return array_filter([
            'source_id' => $source->source_id,
            'source_url' => $source->source_url,
            'task_id' => $task->task_id,
            'job_id' => $job->job_id,
            'heap_id' => $task->dataset_id,
            'dataset_id' => $task->dataset_id,
            'upload' => $upload,
            'converter_mode' => $customConverter ? 'custom' : 'native',
            'custom_converter_profile_path' => $customConverter['profile_path'] ?? null,
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
            'metadata' => [
                'request' => $metadata['request'] ?? null,
            ],
            'storage' => [
                'mode' => $this->storageMode(),
                'shared_root' => $this->sharedRoot(),
                'object_prefix' => $this->objectPrefix(),
            ],
            'task_queues' => [
                'workflow' => $this->config->get('temporal.task_queues.workflow', 'rag-workflow-task-queue'),
                'converter' => $this->config->get('temporal.task_queues.converter', 'rag-converter-task-queue'),
                'ingestion' => $this->config->get('temporal.task_queues.ingestion', 'rag-ingestion-task-queue'),
            ],
            'ingestion' => [
                'provider' => $modelRuntime['provider'],
                'graph_model' => $modelRuntime['graph_model'],
                'embedding_model' => $modelRuntime['embedding_model'],
                'graph' => $this->graphEnabled($metadata),
                'collection' => $heap['qdrant_collection'] ?? null,
                'neo4j_namespace' => $heap['neo4j_namespace'] ?? null,
                'chunk_chars' => (int) $this->config->get('config.chunk_size', env('CHUNK_SIZE', 1200)),
                'chunk_overlap' => (int) $this->config->get('config.chunk_overlap_size', env('CHUNK_OVERLAP_SIZE', 250)),
                'batch_size' => (int) $this->config->get('config.ingest_batch_size', 64),
            ],
            'external_services' => $this->config->get('temporal.external_services', []),
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function sourceId(string $heapId, string $url): string
    {
        return 'source_'.substr(hash('sha256', $heapId.'|'.$url), 0, 32);
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

    /**
     * @param array<string, mixed> $metadata
     */
    private function graphEnabled(array $metadata): bool
    {
        $request = is_array($metadata['request'] ?? null) ? $metadata['request'] : [];
        $requestMetadata = is_array($request['metadata'] ?? null) ? $request['metadata'] : [];

        return filter_var(
            $requestMetadata['graph'] ?? $metadata['graph'] ?? $this->config->get('temporal.ingestion.graph', false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }
}
