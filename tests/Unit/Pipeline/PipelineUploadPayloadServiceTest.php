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
            'Uploaded files are stored as app metadata. Source URL ingestion is orchestrated by IngestSourceWorkflow.',
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
        $this->assertSame('File uploads are stored as metadata only in the Temporal source-ingestion migration.', $metadata['status_note']);
        $this->assertSame('sample.pdf', $metadata['original_filename']);
        $this->assertSame('/shared/uploads/sample.pdf', $metadata['uploaded_path']);
        $this->assertTrue($metadata['graph']);
        $this->assertSame('hawki_upload_dataset', $metadata['dataset']['qdrant_collection']);
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
