<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Uploads;

use App\Models\Dataset;
use App\Services\Pipeline\Tasks\PipelineTaskMetadataService;
use App\Services\Pipeline\Values\PipelineStoredUpload;
use App\Services\Pipeline\Values\PipelineUploadInput;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineUploadPayloadService
{
    public function __construct(
        private PipelineTaskMetadataService $metadata,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function taskMetadata(
        Dataset $dataset,
        PipelineUploadInput $input,
        PipelineStoredUpload $storedUpload,
    ): array {
        return [
            'request' => [
                'source' => 'pipeline-controller',
                'mode' => 'uploaded_file_convert_ingest',
                'metadata' => [
                    'label' => $storedUpload->originalName,
                    'source' => 'pipeline-controller',
                    'graph' => $input->graph,
                ],
            ],
            'orchestration' => 'temporal',
            'temporal' => [
                'note' => 'Uploaded files are stored as app metadata. Source URL ingestion is orchestrated by IngestSourceWorkflow.',
            ],
            'dataset' => $this->metadata->dataset($dataset),
            'upload' => [
                'original_filename' => $storedUpload->originalName,
                'local_path' => $storedUpload->localPath,
                'content_hash' => $storedUpload->contentHash,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jobMetadata(
        Dataset $dataset,
        PipelineUploadInput $input,
        PipelineStoredUpload $storedUpload,
    ): array {
        return [
            'source' => 'pipeline-controller',
            'mode' => 'uploaded_file_convert_ingest',
            'status_note' => 'File uploads are stored as metadata only in the Temporal source-ingestion migration.',
            'original_filename' => $storedUpload->originalName,
            'uploaded_path' => $storedUpload->localPath,
            'graph' => $input->graph,
            'dataset' => $this->metadata->dataset($dataset),
        ];
    }
}
