<?php

namespace App\Http\Controllers;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Datasets\DatasetService;
use App\Services\Pipeline\PipelineEvent;
use App\Services\Pipeline\PipelineEventBus;
use App\Services\Pipeline\PipelineTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PipelineControlController extends Controller
{
    public function __construct(
        private readonly DatasetService $datasets,
        private readonly PipelineTaskService $tasks,
        private readonly PipelineEventBus $events,
    ) {
    }

    public function uploadFile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|max:102400',
            'dataset_id' => 'nullable|string|max:160',
            'datasetId' => 'nullable|string|max:160',
            'graph' => 'nullable',
        ]);

        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return response()->json([
                'success' => false,
                'message' => 'Upload a readable document file.',
            ], 422);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        $supported = array_values(array_filter(array_map(
            static fn (string $value): string => ltrim(strtolower(trim($value)), '.'),
            config('file_converter.supported_extensions', ['pdf', 'doc', 'docx']),
        )));

        if (!in_array($extension, $supported, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unsupported converter input. Supported file types: ' . implode(', ', $supported) . '.',
            ], 422);
        }

        $datasetId = $this->stringValue($validated['dataset_id'] ?? $validated['datasetId'] ?? null) ?? 'controller-uploads';
        $graph = filter_var($validated['graph'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $graph = $graph ?? true;
        $dataset = $this->datasets->ensure($datasetId);
        $taskId = 'task_controller_upload_' . now()->format('Ymd_His') . '_' . Str::lower(Str::random(6));
        $taskRoot = $this->taskRoot($taskId);
        File::ensureDirectoryExists($taskRoot);

        $originalName = $file->getClientOriginalName() ?: "uploaded.{$extension}";
        $baseName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) ?: 'uploaded-document';
        $targetName = $baseName . '-' . Str::lower(Str::random(8)) . '.' . $extension;
        $file->move($taskRoot, $targetName);
        $localPath = $taskRoot . DIRECTORY_SEPARATOR . $targetName;
        $contentHash = hash_file('sha256', $localPath) ?: hash('sha256', $localPath);
        $jobId = 'convert_' . substr(hash('sha256', $taskId . '|' . $contentHash . '|' . $localPath), 0, 24);
        $sourceUrl = 'upload://' . $originalName;
        $now = Carbon::now();

        $task = PipelineTask::query()->create([
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
                        'graph' => $graph,
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
            'graph' => $graph,
            'dataset' => [
                'dataset_id' => $dataset->dataset_id,
                'qdrant_collection' => $dataset->qdrant_collection,
                'neo4j_namespace' => $dataset->neo4j_namespace,
            ],
        ];

        $job = PipelineJob::query()->create([
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
        } catch (Throwable $exception) {
            $failedAt = Carbon::now();
            $job->forceFill([
                'status' => PipelineJob::STATUS_FAILED,
                'error_message' => 'Unable to publish file.discovered event: ' . $exception->getMessage(),
                'finished_at' => $failedAt,
            ])->save();
            $task->forceFill([
                'status' => PipelineTask::STATUS_FAILED,
                'finished_at' => $failedAt,
            ])->save();

            Log::warning('Pipeline controller file upload event publish failed.', [
                'task_id' => $task->task_id,
                'job_id' => $job->job_id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The file was stored, but RabbitMQ did not accept the converter job.',
                'taskId' => $task->task_id,
                'jobId' => $job->job_id,
                'datasetId' => $task->dataset_id,
                'error' => $exception->getMessage(),
                'dashboardUrl' => url('/pipeline-dashboard?task_id=' . rawurlencode($task->task_id)),
            ], 502);
        }

        return response()->json([
            'success' => true,
            'taskId' => $task->task_id,
            'jobId' => $job->job_id,
            'datasetId' => $task->dataset_id,
            'task' => $this->tasks->show($task->task_id),
            'dashboardUrl' => url('/pipeline-dashboard?task_id=' . rawurlencode($task->task_id)),
            'controllerUrl' => url('/pipeline-controller'),
        ], 201);
    }

    private function taskRoot(string $taskId): string
    {
        return rtrim((string) config('communication.rabbitmq.pipeline_ingestion.shared_storage_root', '/app/shared'), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $taskId;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
