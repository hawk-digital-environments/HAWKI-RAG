<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Pipeline\ListPipelineTaskEventsRequest;
use App\Http\Requests\Pipeline\ListPipelineTasksRequest;
use App\Http\Requests\Pipeline\StartPipelineTaskRequest;
use App\Http\Requests\Pipeline\UpsertPipelineJobRequest;
use App\Services\Pipeline\PipelineService;
use Illuminate\Http\JsonResponse;

class PipelineTaskController extends Controller
{
    public function __construct(
        private readonly PipelineService $pipeline,
    ) {}

    public function index(ListPipelineTasksRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'tasks' => $this->pipeline->tasks->list($request->limit()),
        ]);
    }

    public function start(StartPipelineTaskRequest $request): JsonResponse
    {
        $task = $this->pipeline->tasks->start($request->validated());
        $payload = $this->pipeline->tasks->show($task->task_id);

        return response()->json([
            'success' => true,
            'taskId' => $task->task_id,
            'task' => $payload,
        ], 201);
    }

    public function show(string $taskId): JsonResponse
    {
        $task = $this->pipeline->tasks->show($taskId);
        if (! $task) {
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
            'jobs' => $this->pipeline->tasks->jobs($taskId),
        ]);
    }

    public function failedJobs(string $taskId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'taskId' => $taskId,
            'jobs' => $this->pipeline->tasks->failedJobs($taskId),
        ]);
    }

    public function events(ListPipelineTaskEventsRequest $request, string $taskId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'taskId' => $taskId,
            'events' => $this->pipeline->tasks->recentEvents($taskId, $request->limit(), $request->filters()),
            'filters' => $this->pipeline->tasks->eventFilters($taskId),
        ]);
    }

    public function upsertJob(UpsertPipelineJobRequest $request, string $taskId): JsonResponse
    {
        $job = $this->pipeline->tasks->upsertJob($taskId, $request->validated());

        return response()->json([
            'success' => true,
            'taskId' => $taskId,
            'jobId' => $job->job_id,
            'job' => $job->fresh(),
        ]);
    }

    public function retry(string $taskId): JsonResponse
    {
        return $this->taskActionResponse($taskId, $this->pipeline->tasks->retryFailedJobs($taskId));
    }

    public function retryFailedJobs(string $taskId): JsonResponse
    {
        return $this->retry($taskId);
    }

    private function taskActionResponse(string $taskId, mixed $task): JsonResponse
    {
        if (! $task) {
            return response()->json([
                'success' => false,
                'message' => "Pipeline task {$taskId} was not found.",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'taskId' => $taskId,
            'task' => $this->pipeline->tasks->show($taskId),
        ]);
    }
}
