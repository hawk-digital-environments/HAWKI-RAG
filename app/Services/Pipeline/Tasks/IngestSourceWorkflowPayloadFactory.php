<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Document\Values\ManagedDocumentId;
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
        private ClockInterface $clock = new Clock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function input(PipelineTask $task, PipelineJob $job, IngestionSource $source): array
    {
        $metadata = $source->metadata ?? [];
        $refresh = is_array($metadata['refresh'] ?? null) ? $metadata['refresh'] : [];

        $upload = is_array($metadata['upload'] ?? null) ? $metadata['upload'] : null;
        $customConverter = is_array($metadata['custom_converter'] ?? null)
            ? $metadata['custom_converter']
            : null;
        $request = is_array($metadata['request'] ?? null) ? $metadata['request'] : [];
        $requestMetadata = ManagedDocumentId::normalizeRequestMetadata(
            is_array($request['metadata'] ?? null) ? $request['metadata'] : [],
        );
        if ($requestMetadata !== []) {
            $request['metadata'] = $requestMetadata;
        }

        $managedDocumentId = ManagedDocumentId::fromRequestMetadata($requestMetadata);
        $datasetMetadata = is_array($metadata['dataset'] ?? null)
            ? $metadata['dataset']
            : (is_array($task->metadata['dataset'] ?? null) ? $task->metadata['dataset'] : []);
        $datasetModel = trim((string) ($datasetMetadata['embedding_model'] ?? ''));
        $datasetProvider = $this->datasetProvider($datasetMetadata, $datasetModel);
        $modelRuntime = $datasetProvider !== ''
            ? $this->settings->modelRuntimeForProvider($datasetProvider)
            : $this->settings->modelRuntime();
        $embeddingModel = $datasetModel !== '' ? $datasetModel : $modelRuntime['embedding_model'];

        return array_filter([
            'source_id' => $source->source_id,
            'source_url' => $source->source_url,
            'task_id' => $task->task_id,
            'job_id' => $job->job_id,
            'dataset_id' => $task->dataset_id,
            'managed_document_id' => $managedDocumentId?->value,
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
                'request' => $request,
            ],
            'storage' => [
                'shared_root' => $this->sharedRoot(),
            ],
            'task_queues' => [
                'workflow' => $this->config->get('temporal.task_queues.workflow', 'rag-workflow-task-queue'),
                'scraper' => $this->config->get('temporal.task_queues.scraper', 'rag-scraper-task-queue'),
                'converter' => $this->config->get('temporal.task_queues.converter', 'rag-converter-task-queue'),
                'ingestion' => $this->config->get('temporal.task_queues.ingestion', 'rag-ingestion-task-queue'),
                'indexer' => $this->config->get(
                    'temporal.task_queues.indexer',
                    $this->config->get('temporal.task_queues.ingestion', 'rag-ingestion-task-queue'),
                ),
            ],
            'ingestion' => [
                'provider' => $modelRuntime['provider'],
                'graph_model' => $modelRuntime['graph_model'],
                'embedding_model' => $embeddingModel,
                'vision_model' => $modelRuntime['vision_model'],
                'graph' => $this->graphEnabled($metadata),
                'collection' => $datasetMetadata['qdrant_collection'] ?? null,
                'neo4j_namespace' => $datasetMetadata['neo4j_namespace'] ?? null,
                'chunk_chars' => (int) $this->config->get('config.chunk_size', 1200),
                'chunk_overlap' => (int) $this->config->get('config.chunk_overlap_size', 250),
                'batch_size' => (int) $this->config->get('config.ingest_batch_size', 64),
            ],
            'external_services' => $this->config->get('temporal.external_services', []),
        ], static fn (mixed $value): bool => $value !== null);
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
        $base = rtrim($this->sharedRoot(), '/').'/sources/'.$sourceId;

        return [
            'raw' => $base.'/raw/',
            'markdown' => $base.'/markdown/',
        ];
    }

    private function manifestPath(string $sourceId): string
    {
        $base = rtrim($this->sharedRoot(), '/').'/sources/'.$sourceId;

        return $base.'/ingest/manifest.json';
    }

    private function sharedRoot(): string
    {
        $root = trim((string) $this->config->get('temporal.storage.shared_root', '/shared'));
        $normalized = rtrim($root, DIRECTORY_SEPARATOR);
        if (
            $normalized === ''
            || ! str_starts_with($normalized, DIRECTORY_SEPARATOR)
            || preg_match('#(^|/)\.{1,2}(/|$)#', $normalized) === 1
        ) {
            throw new \LogicException(
                'temporal.storage.shared_root must be a canonical absolute directory below the filesystem root.',
            );
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function graphEnabled(array $metadata): bool
    {
        $request = is_array($metadata['request'] ?? null) ? $metadata['request'] : [];
        $requestMetadata = ManagedDocumentId::normalizeRequestMetadata(
            is_array($request['metadata'] ?? null) ? $request['metadata'] : [],
        );

        return filter_var(
            $requestMetadata['graph'] ?? $metadata['graph'] ?? $this->config->get('temporal.ingestion.graph', false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /**
     * @param  array<string, mixed>  $datasetMetadata
     */
    private function datasetProvider(array $datasetMetadata, string $embeddingModel): string
    {
        $provider = strtolower(trim((string) ($datasetMetadata['embedding_provider'] ?? '')));
        if ($provider !== '') {
            return $provider;
        }

        // Tasks created before embedding_provider was persisted may still carry
        // LiteLLM's HAWKI aliases. Preserve that vector space during rollout.
        return str_starts_with($embeddingModel, 'hawki-') ? 'litellm' : '';
    }
}
