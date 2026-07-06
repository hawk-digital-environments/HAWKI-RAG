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
        ?string $customConverterProfilePath = null,
    ): array {
        return [
            'request' => [
                'source' => 'pipeline-upload-api',
                'mode' => 'uploaded_file_convert_ingest',
                'metadata' => [
                    'label' => $storedUpload->originalName,
                    'source' => 'pipeline-upload-api',
                    'graph' => $input->graph,
                    'converter_mode' => $input->converterMode,
                ],
            ],
            'orchestration' => 'temporal',
            'temporal' => [
                'note' => 'Uploaded files are handed to IngestSourceWorkflow through shared storage.',
            ],
            'heap' => $this->metadata->heap($dataset),
            'upload' => [
                'original_filename' => $storedUpload->originalName,
                'local_path' => $storedUpload->localPath,
                'content_hash' => $storedUpload->contentHash,
            ],
            'custom_converter' => $this->customConverterMetadata($input, $customConverterProfilePath),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jobMetadata(
        Dataset $dataset,
        PipelineUploadInput $input,
        PipelineStoredUpload $storedUpload,
        ?string $customConverterProfilePath = null,
    ): array {
        return [
            'source' => 'pipeline-upload-api',
            'mode' => 'uploaded_file_convert_ingest',
            'status_note' => 'File upload handed to Temporal for conversion and ingestion.',
            'original_filename' => $storedUpload->originalName,
            'uploaded_path' => $storedUpload->localPath,
            'target_name' => $storedUpload->targetName,
            'extension' => $storedUpload->extension,
            'graph' => $input->graph,
            'converter_mode' => $input->converterMode,
            'custom_converter' => $this->customConverterMetadata($input, $customConverterProfilePath),
            'heap' => $this->metadata->heap($dataset),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function customConverterMetadata(
        PipelineUploadInput $input,
        ?string $customConverterProfilePath,
    ): ?array {
        if (! $input->usesCustomConverter() || $customConverterProfilePath === null) {
            return null;
        }

        return array_filter([
            'enabled' => true,
            'endpoint' => $input->customConverterUrl,
            'start_path' => $input->customConverterStartPath,
            'profile_path' => $customConverterProfilePath,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
