<?php

declare(strict_types=1);

namespace App\Services\TextIngestion;

use App\Models\Dataset;
use App\Services\Settings\SettingsService;
use App\Services\TextIngestion\Values\StoredTextArtifact;
use App\Services\TextIngestion\Values\TextIngestionInput;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
final readonly class TextIngestionWorkflowPayloadFactory
{
    public function __construct(
        private ConfigRepository $config,
        private SettingsService $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function create(
        TextIngestionInput $input,
        StoredTextArtifact $artifact,
        Dataset $dataset,
        string $taskId,
        string $jobId,
    ): array {
        $runtime = $this->settings->modelRuntimeForProvider($dataset->embedding_provider);
        $markdownDirectory = dirname($artifact->markdownPath);

        return [
            'source_id' => $artifact->sourceId,
            'source_url' => $artifact->sourceUrl,
            'task_id' => $taskId,
            'job_id' => $jobId,
            'dataset_id' => $input->datasetId,
            'external_document_id' => $input->externalDocumentId,
            'markdown_path' => $artifact->markdownPath,
            'markdown_output_path' => $markdownDirectory,
            'ingest_manifest_path' => dirname($markdownDirectory).'/ingest/manifest.json',
            'content_hash' => $artifact->contentHash,
            'display_name' => $input->displayName,
            'storage' => [
                'mode' => 'shared',
                'shared_root' => $this->config->get('temporal.storage.shared_root', '/shared'),
            ],
            'task_queues' => [
                'workflow' => $this->config->get('temporal.task_queues.workflow', 'rag-workflow-task-queue'),
                'indexer' => $this->config->get('temporal.task_queues.indexer', 'rag-ingestion-task-queue'),
                'ingestion' => $this->config->get('temporal.task_queues.ingestion', 'rag-ingestion-task-queue'),
            ],
            'ingestion' => [
                'provider' => $runtime['provider'],
                'embedding_model' => $dataset->embedding_model,
                'graph_model' => $runtime['graph_model'],
                'vision_model' => $runtime['vision_model'],
                'graph' => false,
                'collection' => $dataset->qdrant_collection,
                'neo4j_namespace' => $dataset->neo4j_namespace,
                'chunk_chars' => (int) $this->config->get('config.chunk_size', 1200),
                'chunk_overlap' => (int) $this->config->get('config.chunk_overlap_size', 250),
                'batch_size' => (int) $this->config->get('config.ingest_batch_size', 64),
            ],
        ];
    }
}
