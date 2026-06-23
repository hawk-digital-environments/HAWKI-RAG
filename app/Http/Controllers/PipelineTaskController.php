<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Pipeline\ListPipelineTaskEventsRequest;
use App\Http\Requests\Pipeline\ListPipelineTasksRequest;
use App\Http\Requests\Pipeline\StartPipelineTaskRequest;
use App\Http\Requests\Pipeline\UpsertPipelineJobRequest;
use App\Services\Pipeline\PipelineService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

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

    public function stageLogs(string $taskId, string $stage): JsonResponse
    {
        if (! $this->pipeline->logs->isSupportedStage($stage)) {
            return response()->json([
                'success' => false,
                'message' => "Pipeline stage {$stage} does not have logs.",
            ], 422);
        }

        $log = $this->pipeline->logs->forStage($taskId, $stage);
        if (! $log) {
            return response()->json([
                'success' => false,
                'message' => "Pipeline task {$taskId} was not found.",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'taskId' => $taskId,
            'log' => $log,
        ]);
    }

    public function downloadStageLogs(string $taskId, string $stage): Response
    {
        if (! $this->pipeline->logs->isSupportedStage($stage)) {
            return response("Pipeline stage {$stage} does not have logs.", 422, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        $log = $this->pipeline->logs->reportForStage($taskId, $stage);
        if (! $log) {
            return response("Pipeline task {$taskId} was not found.", 404, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        return response((string) $log['text'], 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$log['filename'].'"',
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

    public function cancel(string $taskId): JsonResponse
    {
        return $this->taskActionResponse($taskId, $this->pipeline->tasks->cancel($taskId));
    }

    public function destroy(string $taskId): JsonResponse
    {
        $result = $this->pipeline->tasks->delete($taskId);
        if (! $result) {
            return response()->json([
                'success' => false,
                'message' => "Pipeline task {$taskId} was not found.",
            ], 404);
        }

        if (! ($result['deleted'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => "Pipeline task {$taskId} storage cleanup failed; task history was not deleted.",
                'storageCleanup' => $result['storageCleanup'] ?? null,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'taskId' => $taskId,
            'deleted' => true,
            'storageCleanup' => $result['storageCleanup'] ?? null,
        ]);
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
