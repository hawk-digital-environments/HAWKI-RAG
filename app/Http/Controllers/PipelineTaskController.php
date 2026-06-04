<?php

namespace App\Http\Controllers;

use App\Services\Pipeline\PipelineTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineTaskController extends Controller
{
    public function __construct(
        private readonly PipelineTaskService $tasks,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json([
            'success' => true,
            'tasks' => $this->tasks->list((int) ($validated['limit'] ?? 30)),
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'task_id' => 'nullable|string',
            'taskId' => 'nullable|string',
            'dataset_id' => 'nullable|string',
            'datasetId' => 'nullable|string',
            'sitemap_url' => 'nullable|string',
            'sitemapUrl' => 'nullable|string',
            'sitemap_path' => 'nullable|string',
            'sitemapPath' => 'nullable|string',
            'source_url' => 'nullable|string',
            'sourceUrl' => 'nullable|string',
            'urls' => 'nullable',
            'metadata' => 'nullable|array',
        ]);

        $task = $this->tasks->start($validated);
        $payload = $this->tasks->show($task->task_id);

        return response()->json([
            'success' => true,
            'taskId' => $task->task_id,
            'task' => $payload,
        ], 201);
    }

    public function show(string $taskId): JsonResponse
    {
        $task = $this->tasks->show($taskId);
        if (!$task) {
            return response()->json([
                'success' => false,
                'message' => "Pipeline task {$taskId} was not found.",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'task' => $task,
        ]);
    }

    public function jobs(string $taskId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'taskId' => $taskId,
            'jobs' => $this->tasks->jobs($taskId),
        ]);
    }

    public function failedJobs(string $taskId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'taskId' => $taskId,
            'jobs' => $this->tasks->failedJobs($taskId),
        ]);
    }

    public function events(Request $request, string $taskId): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:250',
            'event_type' => 'nullable|string',
            'eventType' => 'nullable|string',
            'job_id' => 'nullable|string',
            'jobId' => 'nullable|string',
        ]);
        $filters = [
            'event_type' => $validated['event_type'] ?? $validated['eventType'] ?? null,
            'job_id' => $validated['job_id'] ?? $validated['jobId'] ?? null,
        ];

        return response()->json([
            'success' => true,
            'taskId' => $taskId,
            'events' => $this->tasks->recentEvents($taskId, (int) ($validated['limit'] ?? 100), $filters),
            'filters' => $this->tasks->eventFilters($taskId),
        ]);
    }

    public function upsertJob(Request $request, string $taskId): JsonResponse
    {
        $validated = $request->validate([
            'job_id' => 'nullable|string',
            'jobId' => 'nullable|string',
            'parent_job_id' => 'nullable|string',
            'parentJobId' => 'nullable|string',
            'job_type' => 'nullable|string',
            'jobType' => 'nullable|string',
            'source_url' => 'nullable|string',
            'sourceUrl' => 'nullable|string',
            'local_path' => 'nullable|string',
            'localPath' => 'nullable|string',
            'content_hash' => 'nullable|string',
            'contentHash' => 'nullable|string',
            'status' => 'nullable|string',
            'error_message' => 'nullable|string',
            'errorMessage' => 'nullable|string',
            'started_at' => 'nullable|string',
            'startedAt' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $job = $this->tasks->upsertJob($taskId, $validated);

        return response()->json([
            'success' => true,
            'taskId' => $taskId,
            'jobId' => $job->job_id,
            'job' => $job->fresh(),
        ]);
    }

    public function retry(string $taskId): JsonResponse
    {
        return $this->taskActionResponse($taskId, $this->tasks->retryFailedJobs($taskId));
    }

    public function retryFailedJobs(string $taskId): JsonResponse
    {
        return $this->retry($taskId);
    }

    private function taskActionResponse(string $taskId, mixed $task): JsonResponse
    {
        if (!$task) {
            return response()->json([
                'success' => false,
                'message' => "Pipeline task {$taskId} was not found.",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'taskId' => $taskId,
            'task' => $this->tasks->show($taskId),
        ]);
    }
}
