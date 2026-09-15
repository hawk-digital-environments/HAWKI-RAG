<?php

declare(strict_types=1);

namespace App\Services\TextIngestion;

use App\Models\Dataset;
use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Settings\SettingsService;
use App\Services\TextIngestion\Exceptions\TextIngestionRecoveryException;
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
        return $this->payload(
            dataset: $dataset,
            sourceId: $artifact->sourceId,
            sourceUrl: $artifact->sourceUrl,
            taskId: $taskId,
            jobId: $jobId,
            externalDocumentId: $input->externalDocumentId,
            markdownPath: $artifact->markdownPath,
            contentHash: $artifact->contentHash,
            displayName: $input->displayName,
        );
    }

    /**
     * Rebuild direct-text workflow input exclusively from persisted state.
     *
     * @return array<string, mixed>
     */
    public function createFromPersisted(
        Dataset $dataset,
        PipelineTask $task,
        PipelineJob $job,
        IngestionSource $source,
    ): array {
        $sourceMetadata = is_array($source->metadata) ? $source->metadata : [];
        $jobMetadata = is_array($job->metadata) ? $job->metadata : [];
        $textIngestion = is_array($sourceMetadata['text_ingestion'] ?? null)
            ? $sourceMetadata['text_ingestion']
            : [];
        $request = is_array($sourceMetadata['request'] ?? null)
            ? $sourceMetadata['request']
            : (is_array($jobMetadata['request'] ?? null) ? $jobMetadata['request'] : []);

        return $this->payload(
            dataset: $dataset,
            sourceId: $this->requiredString($source->source_id, $job, 'source_id'),
            sourceUrl: $this->requiredString(
                $source->source_url ?: $job->source_url,
                $job,
                'source_url',
            ),
            taskId: $this->requiredString($task->task_id, $job, 'task_id'),
            jobId: $this->requiredString($job->job_id, $job, 'job_id'),
            externalDocumentId: $this->requiredString(
                $textIngestion['external_document_id'] ?? $request['external_document_id'] ?? null,
                $job,
                'external_document_id',
            ),
            markdownPath: $this->requiredString(
                $textIngestion['markdown_path']
                    ?? $jobMetadata['markdown_path']
                    ?? $job->local_path,
                $job,
                'markdown_path',
            ),
            contentHash: $this->requiredString(
                $source->content_hash ?: $job->content_hash,
                $job,
                'content_hash',
            ),
            displayName: $this->optionalString($request['display_name'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        Dataset $dataset,
        string $sourceId,
        string $sourceUrl,
        string $taskId,
        string $jobId,
        string $externalDocumentId,
        string $markdownPath,
        string $contentHash,
        ?string $displayName,
    ): array {
        $runtime = $this->settings->modelRuntimeForProvider($dataset->embedding_provider);
        $markdownDirectory = dirname($markdownPath);

        return [
            'source_id' => $sourceId,
            'source_url' => $sourceUrl,
            'task_id' => $taskId,
            'job_id' => $jobId,
            'dataset_id' => $dataset->dataset_id,
            'external_document_id' => $externalDocumentId,
            'markdown_path' => $markdownPath,
            'markdown_output_path' => $markdownDirectory,
            'ingest_manifest_path' => dirname($markdownDirectory).'/ingest/manifest.json',
            'content_hash' => $contentHash,
            'display_name' => $displayName,
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

    private function requiredString(mixed $value, PipelineJob $job, string $field): string
    {
        $string = $this->optionalString($value);
        if ($string === null) {
            throw TextIngestionRecoveryException::missingPersistedField(
                (string) $job->job_id,
                $field,
            );
        }

        return $string;
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
