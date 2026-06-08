<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\Dataset;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Datasets\DatasetService;
use App\Services\Pipeline\Exceptions\PipelineUploadStorageException;
use App\Services\Pipeline\Repositories\PipelineJobRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Values\PipelineStoredUpload;
use App\Services\Pipeline\Values\PipelineUploadInput;
use App\Services\Pipeline\Values\PipelineUploadResult;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

#[Singleton]
class PipelineUploadService
{
    public function __construct(
        private readonly DatasetService $datasets,
        private readonly PipelineTaskService $tasks,
        private readonly PipelineEventBus $events,
        private readonly PipelineTaskRepository $taskRepository,
        private readonly PipelineJobRepository $jobRepository,
        private readonly PipelineUploadStorage $storage,
    ) {
    }

    public function upload(PipelineUploadInput $input, ?UploadedFile $file): PipelineUploadResult
    {
        if (!$file || !$file->isValid()) {
            return PipelineUploadResult::fromPayload([
                'success' => false,
                'message' => 'Upload a readable document file.',
            ], 422);
        }

        $extension = $this->storage->extensionFor($file);
        $supported = $this->supportedExtensions();

        if (!in_array($extension, $supported, true)) {
            return PipelineUploadResult::fromPayload([
                'success' => false,
                'message' => 'Unsupported converter input. Supported file types: ' . implode(', ', $supported) . '.',
            ], 422);
        }

        $taskId = 'task_controller_upload_' . now()->format('Ymd_His') . '_' . Str::lower(Str::random(6));

        try {
            $storedUpload = $this->storage->store($taskId, $file, $extension);
        } catch (PipelineUploadStorageException $exception) {
            Log::warning($exception->logMessage(), array_merge([
                'dataset_id' => $input->datasetId,
                'task_id' => $taskId,
                'error' => $exception->getMessage(),
            ], $exception->logContext()));

            return PipelineUploadResult::fromPayload([
                'success' => false,
                'message' => $exception->responseMessage(),
                'datasetId' => $input->datasetId,
                'taskId' => null,
                'jobId' => null,
                'error' => $exception->getMessage(),
            ], 500);
        }

        $dataset = $this->datasets->ensure($input->datasetId);
        $jobId = $this->convertJobId($taskId, $storedUpload);
        $sourceUrl = $this->sourceUrl($storedUpload);
        $now = Carbon::now();

        $task = $this->taskRepository->create([
            'task_id' => $taskId,
            'dataset_id' => $dataset->dataset_id,
            'status' => PipelineTask::STATUS_RUNNING,
            'started_at' => $now,
            'counters' => [],
            'metadata' => $this->taskMetadata($dataset, $input, $storedUpload),
        ]);

        $metadata = $this->jobMetadata($dataset, $input, $storedUpload);

        $job = $this->jobRepository->create([
            'job_id' => $jobId,
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_CONVERT,
            'source_url' => $sourceUrl,
            'local_path' => $storedUpload->localPath,
            'content_hash' => $storedUpload->contentHash,
            'status' => PipelineJob::STATUS_QUEUED,
            'started_at' => $now,
            'metadata' => $metadata,
        ]);

        $payload = $this->fileDiscoveredPayload($task, $job, $sourceUrl, $storedUpload, $metadata);

        try {
            $this->events->publish(PipelineEvent::FILE_DISCOVERED, $payload);
        } catch (\Throwable $exception) {
            $failedAt = Carbon::now();
            $job = $this->jobRepository->markFailed(
                $job,
                'Unable to publish file.discovered event: ' . $exception->getMessage(),
                $failedAt,
            );
            $task = $this->taskRepository->markFailed($task, $failedAt);

            Log::warning('Pipeline controller file upload event publish failed.', [
                'task_id' => $task->task_id,
                'job_id' => $job->job_id,
                'error' => $exception->getMessage(),
            ]);

            return PipelineUploadResult::fromPayload([
                'success' => false,
                'message' => 'The file was stored, but RabbitMQ did not accept the converter job.',
                'taskId' => $task->task_id,
                'jobId' => $job->job_id,
                'datasetId' => $task->dataset_id,
                'error' => $exception->getMessage(),
                'dashboardUrl' => url('/pipeline-dashboard?task_id=' . rawurlencode($task->task_id)),
            ], 502);
        }

        return PipelineUploadResult::fromPayload([
            'success' => true,
            'taskId' => $task->task_id,
            'jobId' => $job->job_id,
            'datasetId' => $task->dataset_id,
            'task' => $this->tasks->show($task->task_id),
            'dashboardUrl' => url('/pipeline-dashboard?task_id=' . rawurlencode($task->task_id)),
            'controllerUrl' => url('/pipeline-controller'),
        ], 201);
    }

    /**
     * @return list<string>
     */
    private function supportedExtensions(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): string => ltrim(strtolower(trim($value)), '.'),
            config('file_converter.supported_extensions', ['pdf', 'doc', 'docx']),
        )));
    }

    private function convertJobId(string $taskId, PipelineStoredUpload $storedUpload): string
    {
        return 'convert_' . substr(
            hash('sha256', $taskId . '|' . $storedUpload->contentHash . '|' . $storedUpload->localPath),
            0,
            24,
        );
    }

    private function sourceUrl(PipelineStoredUpload $storedUpload): string
    {
        return 'upload://' . $storedUpload->originalName;
    }

    /**
     * @return array<string, mixed>
     */
    private function taskMetadata(
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
            'dataset' => $this->datasetMetadata($dataset),
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
    private function jobMetadata(
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
            'dataset' => $this->datasetMetadata($dataset),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private function fileDiscoveredPayload(
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

    /**
     * @return array<string, mixed>
     */
    private function datasetMetadata(Dataset $dataset): array
    {
        return [
            'dataset_id' => $dataset->dataset_id,
            'qdrant_collection' => $dataset->qdrant_collection,
            'neo4j_namespace' => $dataset->neo4j_namespace,
        ];
    }
}
