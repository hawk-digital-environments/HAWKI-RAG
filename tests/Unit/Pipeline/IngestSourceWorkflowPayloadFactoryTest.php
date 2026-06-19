<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Tasks\IngestSourceWorkflowPayloadFactory;
use Tests\TestCase;

class IngestSourceWorkflowPayloadFactoryTest extends TestCase
{
    public function test_it_builds_small_reference_payload_for_temporal_workflow(): void
    {
        config()->set('temporal.storage.mode', 'shared');
        config()->set('temporal.storage.shared_root', '/shared');
        config()->set('temporal.task_queues.workflow', 'rag-workflow-task-queue');
        config()->set('temporal.task_queues.scraper', 'rag-scraper-task-queue');
        config()->set('temporal.task_queues.converter', 'rag-converter-task-queue');
        config()->set('temporal.task_queues.ingestion', 'rag-ingestion-task-queue');
        config()->set('temporal.ingestion.provider', 'ollama');
        config()->set('temporal.ingestion.graph', true);

        $factory = app(IngestSourceWorkflowPayloadFactory::class);
        $sourceId = $factory->sourceId('dataset-a', 'https://example.edu');
        $paths = $factory->storagePaths($sourceId);

        $payload = $factory->input(
            new PipelineTask([
                'task_id' => 'task-a',
                'dataset_id' => 'dataset-a',
                'metadata' => [
                    'dataset' => [
                        'qdrant_collection' => 'hawki_dataset_a',
                        'neo4j_namespace' => 'hawki_dataset_a',
                    ],
                ],
            ]),
            new PipelineJob([
                'job_id' => 'ingest-a',
                'job_type' => PipelineJob::TYPE_INGEST,
            ]),
            new IngestionSource([
                'source_id' => $sourceId,
                'source_url' => 'https://example.edu',
                'refresh_cadence' => 'daily',
                'etag' => 'etag-a',
                'last_modified' => 'Fri, 12 Jun 2026 10:00:00 GMT',
                'content_hash' => 'hash-a',
                'document_version' => 'version-a',
                'raw_storage_path' => $paths['raw'],
                'markdown_storage_path' => $paths['markdown'],
                'metadata' => [],
            ]),
        );

        $this->assertSame($sourceId, $payload['source_id']);
        $this->assertSame('https://example.edu', $payload['source_url']);
        $this->assertSame('/shared/sources/'.$sourceId.'/raw/', $payload['raw_output_path']);
        $this->assertSame('/shared/sources/'.$sourceId.'/markdown/', $payload['markdown_output_path']);
        $this->assertSame('/shared/sources/'.$sourceId.'/ingest/manifest.json', $payload['ingest_manifest_path']);
        $this->assertSame('native', $payload['converter_mode']);
        $this->assertSame('daily', $payload['refresh']['cadence']);
        $this->assertSame('etag-a', $payload['refresh']['etag']);
        $this->assertSame('rag-workflow-task-queue', $payload['task_queues']['workflow']);
        $this->assertSame('rag-scraper-task-queue', $payload['task_queues']['scraper']);
        $this->assertSame('rag-converter-task-queue', $payload['task_queues']['converter']);
        $this->assertSame('rag-ingestion-task-queue', $payload['task_queues']['ingestion']);
        $this->assertSame('ollama', $payload['ingestion']['provider']);
        $this->assertSame('llama3.1:8b', $payload['ingestion']['graph_model']);
        $this->assertSame('bge-m3', $payload['ingestion']['embedding_model']);
        $this->assertTrue($payload['ingestion']['graph']);

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('markdown_body', $encoded);
        $this->assertStringNotContainsString('"embedding":[', $encoded);
        $this->assertStringNotContainsString('chunks', $encoded);
    }

    public function test_it_includes_upload_handoff_and_request_graph_override(): void
    {
        config()->set('temporal.storage.mode', 'shared');
        config()->set('temporal.storage.shared_root', '/shared');
        config()->set('temporal.ingestion.graph', true);

        $factory = app(IngestSourceWorkflowPayloadFactory::class);
        $sourceId = $factory->sourceId('dataset-upload', 'upload://sample.pdf|hash');
        $paths = $factory->storagePaths($sourceId);

        $payload = $factory->input(
            new PipelineTask([
                'task_id' => 'task-upload',
                'dataset_id' => 'dataset-upload',
            ]),
            new PipelineJob([
                'job_id' => 'ingest-upload',
                'job_type' => PipelineJob::TYPE_INGEST,
            ]),
            new IngestionSource([
                'source_id' => $sourceId,
                'source_url' => 'upload://sample.pdf',
                'content_hash' => 'hash',
                'raw_storage_path' => $paths['raw'],
                'markdown_storage_path' => $paths['markdown'],
                'metadata' => [
                    'request' => [
                        'metadata' => [
                            'graph' => false,
                        ],
                    ],
                    'upload' => [
                        'original_filename' => 'sample.pdf',
                        'target_name' => 'sample-upload.pdf',
                        'local_path' => '/shared/task/sample-upload.pdf',
                    ],
                ],
            ]),
        );

        $this->assertSame('/shared/task/sample-upload.pdf', $payload['upload']['local_path']);
        $this->assertSame('sample-upload.pdf', $payload['upload']['target_name']);
        $this->assertSame('native', $payload['converter_mode']);
        $this->assertFalse($payload['ingestion']['graph']);
    }

    public function test_it_includes_custom_converter_profile_path_without_secret_material(): void
    {
        config()->set('temporal.storage.mode', 'shared');
        config()->set('temporal.storage.shared_root', '/shared');

        $factory = app(IngestSourceWorkflowPayloadFactory::class);
        $sourceId = $factory->sourceId('dataset-upload', 'upload://diagram.svg|hash');
        $paths = $factory->storagePaths($sourceId);

        $payload = $factory->input(
            new PipelineTask([
                'task_id' => 'task-upload',
                'dataset_id' => 'dataset-upload',
            ]),
            new PipelineJob([
                'job_id' => 'ingest-upload',
                'job_type' => PipelineJob::TYPE_INGEST,
            ]),
            new IngestionSource([
                'source_id' => $sourceId,
                'source_url' => 'upload://diagram.svg',
                'content_hash' => 'hash',
                'raw_storage_path' => $paths['raw'],
                'markdown_storage_path' => $paths['markdown'],
                'metadata' => [
                    'custom_converter' => [
                        'enabled' => true,
                        'endpoint' => 'https://converter.example.test',
                        'profile_path' => '/shared/sources/'.$sourceId.'/secrets/custom_converter.json',
                    ],
                ],
            ]),
        );

        $this->assertSame('custom', $payload['converter_mode']);
        $this->assertSame(
            '/shared/sources/'.$sourceId.'/secrets/custom_converter.json',
            $payload['custom_converter_profile_path'],
        );
        $this->assertStringNotContainsString('converter.example.test', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
