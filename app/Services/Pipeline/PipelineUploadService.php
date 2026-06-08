<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Datasets\DatasetService;
use App\Services\Pipeline\Repositories\PipelineJobRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Values\PipelineUploadInput;
use App\Services\Pipeline\Values\PipelineUploadResult;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
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

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        $supported = $this->supportedExtensions();

        if (!in_array($extension, $supported, true)) {
            return PipelineUploadResult::fromPayload([
                'success' => false,
                'message' => 'Unsupported converter input. Supported file types: ' . implode(', ', $supported) . '.',
            ], 422);
        }

        $taskId = 'task_controller_upload_' . now()->format('Ymd_His') . '_' . Str::lower(Str::random(6));
        $taskRoot = $this->taskRoot($taskId);

        try {
            File::ensureDirectoryExists($taskRoot);
            if (!is_dir($taskRoot) || !is_writable($taskRoot)) {
                throw new \RuntimeException("Upload task directory is not writable: {$taskRoot}");
            }
        } catch (\Throwable $exception) {
            Log::warning('Pipeline controller could not prepare upload storage.', [
                'dataset_id' => $input->datasetId,
                'task_id' => $taskId,
                'task_root' => $taskRoot,
                'error' => $exception->getMessage(),
            ]);

            return PipelineUploadResult::fromPayload([
                'success' => false,
                'message' => 'The upload storage path is not writable. No dataset, task, or job was created.',
                'datasetId' => $input->datasetId,
                'taskId' => null,
                'jobId' => null,
                'error' => $exception->getMessage(),
            ], 500);
        }

        $originalName = $file->getClientOriginalName() ?: "uploaded.{$extension}";
        $baseName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) ?: 'uploaded-document';
        $targetName = $baseName . '-' . Str::lower(Str::random(8)) . '.' . $extension;

        try {
            $file->move($taskRoot, $targetName);
        } catch (\Throwable $exception) {
            File::deleteDirectory($taskRoot);

            Log::warning('Pipeline controller could not move uploaded file.', [
                'dataset_id' => $input->datasetId,
                'task_id' => $taskId,
                'task_root' => $taskRoot,
                'target_name' => $targetName,
                'error' => $exception->getMessage(),
            ]);

            return PipelineUploadResult::fromPayload([
                'success' => false,
                'message' => 'The uploaded file could not be stored. No dataset, task, or job was created.',
                'datasetId' => $input->datasetId,
                'taskId' => null,
                'jobId' => null,
                'error' => $exception->getMessage(),
            ], 500);
        }

        $dataset = $this->datasets->ensure($input->datasetId);
        $localPath = $taskRoot . DIRECTORY_SEPARATOR . $targetName;
        $contentHash = hash_file('sha256', $localPath) ?: hash('sha256', $localPath);
        $jobId = 'convert_' . substr(hash('sha256', $taskId . '|' . $contentHash . '|' . $localPath), 0, 24);
        $sourceUrl = 'upload://' . $originalName;
        $now = Carbon::now();

        $task = $this->taskRepository->create([
            'task_id' => $taskId,
            'dataset_id' => $dataset->dataset_id,
            'status' => PipelineTask::STATUS_RUNNING,
            'started_at' => $now,
            'counters' => [],
            'metadata' => [
                'request' => [
                    'source' => 'pipeline-controller',
                    'mode' => 'uploaded_file_convert_ingest',
                    'metadata' => [
                        'label' => $originalName,
                        'source' => 'pipeline-controller',
                        'graph' => $input->graph,
                    ],
                ],
                'orchestration' => 'laravel',
                'rabbitmq' => ['event_bus' => true],
                'dataset' => [
                    'dataset_id' => $dataset->dataset_id,
                    'qdrant_collection' => $dataset->qdrant_collection,
                    'neo4j_namespace' => $dataset->neo4j_namespace,
                ],
                'upload' => [
                    'original_filename' => $originalName,
                    'local_path' => $localPath,
                    'content_hash' => $contentHash,
                ],
            ],
        ]);

        $metadata = [
            'source' => 'pipeline-controller',
            'mode' => 'uploaded_file_convert_ingest',
            'original_filename' => $originalName,
            'uploaded_path' => $localPath,
            'graph' => $input->graph,
            'dataset' => [
                'dataset_id' => $dataset->dataset_id,
                'qdrant_collection' => $dataset->qdrant_collection,
                'neo4j_namespace' => $dataset->neo4j_namespace,
            ],
        ];

        $job = $this->jobRepository->create([
            'job_id' => $jobId,
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_CONVERT,
            'source_url' => $sourceUrl,
            'local_path' => $localPath,
            'content_hash' => $contentHash,
            'status' => PipelineJob::STATUS_QUEUED,
            'started_at' => $now,
            'metadata' => $metadata,
        ]);

        $payload = [
            'task_id' => $task->task_id,
            'job_id' => $job->job_id,
            'dataset_id' => $task->dataset_id,
            'job_type' => PipelineJob::TYPE_CONVERT,
            'source_url' => $sourceUrl,
            'local_path' => $localPath,
            'content_hash' => $contentHash,
            'status' => PipelineJob::STATUS_QUEUED,
            'metadata' => $metadata,
        ];

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

    private function taskRoot(string $taskId): string
    {
        return rtrim((string) config('communication.rabbitmq.pipeline_ingestion.shared_storage_root', '/app/shared'), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $taskId;
    }
}
