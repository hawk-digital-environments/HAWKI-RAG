<?php

declare(strict_types=1);

namespace Tests\Unit\TextIngestion;

use App\Models\Dataset;
use App\Services\TextIngestion\TextIngestionWorkflowPayloadFactory;
use App\Services\TextIngestion\Values\StoredTextArtifact;
use App\Services\TextIngestion\Values\TextIngestionInput;
use Tests\TestCase;

final class TextIngestionWorkflowPayloadFactoryTest extends TestCase
{
    public function test_it_builds_the_direct_text_workflow_payload(): void
    {
        $input = TextIngestionInput::fromValidated([
            'external_document_id' => 'document-123',
            'dataset_id' => 'default',
            'text' => '# Example',
            'content_format' => 'markdown',
            'display_name' => 'API document',
            'source_url' => 'https://hawki.example/document-123',
            'metadata' => [
                'assistant_id' => 'assistant-42',
            ],
        ]);

        $artifact = StoredTextArtifact::fromStoredText(
            sourceId: 'source-123',
            sourceUrl: 'https://hawki.example/document-123',
            contentHash: 'content-hash',
            markdownPath: '/shared/sources/source-123/markdown/document.md',
        );

        $payload = app(TextIngestionWorkflowPayloadFactory::class)->create(
            input: $input,
            artifact: $artifact,
            dataset: $this->dataset(),
            taskId: 'task-123',
            jobId: 'job-123',
        );

        self::assertSame('source-123', $payload['source_id']);
        self::assertSame('task-123', $payload['task_id']);
        self::assertSame('job-123', $payload['job_id']);
        self::assertSame('default', $payload['dataset_id']);
        self::assertSame(
            '/shared/sources/source-123/markdown/document.md',
            $payload['markdown_path'],
        );
        self::assertArrayNotHasKey('metadata', $payload);
        self::assertFalse($payload['ingestion']['graph']);
    }

    public function test_graph_is_always_disabled(): void
    {
        $input = TextIngestionInput::fromValidated([
            'external_document_id' => 'document-123',
            'dataset_id' => 'default',
            'text' => 'Example',
            'content_format' => 'plain_text',
            'metadata' => [
                'graph' => true,
            ],
        ]);

        $artifact = StoredTextArtifact::fromStoredText(
            sourceId: 'source-123',
            sourceUrl: 'external://document-123',
            contentHash: 'content-hash',
            markdownPath: '/shared/sources/source-123/markdown/document.md',
        );

        $payload = app(TextIngestionWorkflowPayloadFactory::class)->create(
            $input,
            $artifact,
            $this->dataset(),
            'task-123',
            'job-123',
        );

        self::assertFalse($payload['ingestion']['graph']);
    }

    private function dataset(): Dataset
    {
        return new Dataset([
            'dataset_id' => 'default',
            'embedding_provider' => 'ollama',
            'embedding_model' => 'bge-m3',
            'qdrant_collection' => 'hawki_default',
            'neo4j_namespace' => 'hawki_default',
        ]);
    }
}
