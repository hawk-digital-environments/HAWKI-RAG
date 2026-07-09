<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Models\Dataset;
use App\Services\Pipeline\Uploads\PipelineUploadPayloadService;
use App\Services\Pipeline\Values\PipelineStoredUpload;
use App\Services\Pipeline\Values\PipelineUploadInput;
use Tests\TestCase;

class PipelineUploadPayloadServiceTest extends TestCase
{
    public function test_it_builds_upload_task_metadata(): void
    {
        $metadata = app(PipelineUploadPayloadService::class)->taskMetadata(
            $this->dataset(),
            PipelineUploadInput::fromValidated(['dataset_id' => 'upload-dataset', 'graph' => 'false']),
            $this->storedUpload(),
        );

        $this->assertSame('pipeline-controller', $metadata['request']['source']);
        $this->assertSame('uploaded_file_convert_ingest', $metadata['request']['mode']);
        $this->assertSame('sample.pdf', $metadata['request']['metadata']['label']);
        $this->assertFalse($metadata['request']['metadata']['graph']);
        $this->assertSame('temporal', $metadata['orchestration']);
        $this->assertSame(
            'Uploaded files are handed to IngestSourceWorkflow through shared storage.',
            $metadata['temporal']['note'],
        );
        $this->assertSame('upload-dataset', $metadata['dataset']['dataset_id']);
        $this->assertSame('/shared/uploads/sample.pdf', $metadata['upload']['local_path']);
    }

    public function test_it_builds_upload_convert_job_metadata(): void
    {
        $metadata = app(PipelineUploadPayloadService::class)->jobMetadata(
            $this->dataset(),
            PipelineUploadInput::fromValidated(['dataset_id' => 'upload-dataset', 'graph' => 'true']),
            $this->storedUpload(),
        );

        $this->assertSame('pipeline-controller', $metadata['source']);
        $this->assertSame('uploaded_file_convert_ingest', $metadata['mode']);
        $this->assertSame('File upload handed to Temporal for conversion and ingestion.', $metadata['status_note']);
        $this->assertSame('sample.pdf', $metadata['original_filename']);
        $this->assertSame('/shared/uploads/sample.pdf', $metadata['uploaded_path']);
        $this->assertSame('sample-a1b2c3.pdf', $metadata['target_name']);
        $this->assertSame('pdf', $metadata['extension']);
        $this->assertTrue($metadata['graph']);
        $this->assertSame('hawki_upload_dataset', $metadata['dataset']['qdrant_collection']);
    }

    public function test_it_builds_custom_converter_metadata_without_token(): void
    {
        $input = PipelineUploadInput::fromValidated([
            'converter_mode' => 'custom',
            'converter_url' => 'https://converter.example.test',
            'converter_token' => 'secret-api-key',
            'converter_start_path' => '/extract',
        ]);

        $metadata = app(PipelineUploadPayloadService::class)->taskMetadata(
            $this->dataset(),
            $input,
            $this->storedUpload(),
            '/shared/sources/source-1/secrets/custom_converter.json',
        );

        $this->assertSame('custom', $metadata['request']['metadata']['converter_mode']);
        $this->assertSame('https://converter.example.test', $metadata['custom_converter']['endpoint']);
        $this->assertSame('/extract', $metadata['custom_converter']['start_path']);
        $this->assertArrayNotHasKey('status_path', $metadata['custom_converter']);
        $this->assertSame('/shared/sources/source-1/secrets/custom_converter.json', $metadata['custom_converter']['profile_path']);
        $this->assertStringNotContainsString('secret-api-key', json_encode($metadata, JSON_UNESCAPED_SLASHES));
    }

    public function test_it_merges_request_metadata_into_upload_task_metadata(): void
    {
        $metadata = app(PipelineUploadPayloadService::class)->taskMetadata(
            $this->dataset(),
            PipelineUploadInput::fromValidated([
                'dataset_id' => 'upload-dataset',
                'graph' => 'false',
                'request_metadata' => [
                    'assistant_document_id' => 'adoc_upload_1',
                ],
            ]),
            $this->storedUpload(),
        );

        $this->assertSame('adoc_upload_1', $metadata['request']['metadata']['assistant_document_id']);
        $this->assertSame('sample.pdf', $metadata['request']['metadata']['label']);
    }

    private function dataset(): Dataset
    {
        return new Dataset([
            'dataset_id' => 'upload-dataset',
            'qdrant_collection' => 'hawki_upload_dataset',
            'neo4j_namespace' => 'hawki_upload_dataset',
        ]);
    }

    private function storedUpload(): PipelineStoredUpload
    {
        return PipelineStoredUpload::fromStoredFile(
            'sample.pdf',
            'sample-a1b2c3.pdf',
            '/shared/uploads/sample.pdf',
            'sha256-upload-content',
            'pdf',
        );
    }
}
