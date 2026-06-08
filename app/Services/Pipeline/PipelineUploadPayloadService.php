<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\Dataset;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Values\PipelineStoredUpload;
use App\Services\Pipeline\Values\PipelineUploadInput;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineUploadPayloadService
{
    public function __construct(
        private PipelineTaskMetadataService $metadata,
    ) {
    }

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
            'orchestration' => 'laravel',
            'rabbitmq' => ['event_bus' => true],
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
            'original_filename' => $storedUpload->originalName,
            'uploaded_path' => $storedUpload->localPath,
            'graph' => $input->graph,
            'dataset' => $this->metadata->dataset($dataset),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    public function fileDiscovered(
        PipelineTask $task,
        PipelineJob $job,
        string $sourceUrl,
        PipelineStoredUpload $storedUpload,
        array $metadata,
    ): array {
        return [
            'task_id' => $task->task_id,
            'job_id' => $job->job_id,
            'dataset_id' => $task->dataset_id,
            'job_type' => PipelineJob::TYPE_CONVERT,
            'source_url' => $sourceUrl,
            'local_path' => $storedUpload->localPath,
            'content_hash' => $storedUpload->contentHash,
            'status' => PipelineJob::STATUS_QUEUED,
            'metadata' => $metadata,
        ];
    }
}
