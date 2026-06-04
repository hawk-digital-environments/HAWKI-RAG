<?php

namespace App\Http\Controllers;

use App\Services\Pipeline\PipelineRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineRecoveryController extends Controller
{
    public function __construct(
        private readonly PipelineRecoveryService $recovery,
    ) {
    }

    public function failedJobs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:500',
            'task_id' => 'nullable|string',
            'taskId' => 'nullable|string',
            'dataset_id' => 'nullable|string',
            'datasetId' => 'nullable|string',
        ]);

        return response()->json([
            'success' => true,
            'jobs' => $this->recovery->failedJobs($validated),
        ]);
    }

    public function retryJob(string $jobId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'recovery' => $this->recovery->retryJob($jobId),
        ]);
    }

    public function retrySelected(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'job_ids' => 'nullable|array',
            'job_ids.*' => 'string',
            'jobIds' => 'nullable|array',
            'jobIds.*' => 'string',
        ]);

        return response()->json([
            'success' => true,
            'recovery' => $this->recovery->retrySelected($validated['job_ids'] ?? $validated['jobIds'] ?? []),
        ]);
    }

    public function retryAll(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'recovery' => $this->recovery->retryAll(),
        ]);
    }

    public function retryTask(string $taskId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'recovery' => $this->recovery->retryTask($taskId),
        ]);
    }

    public function retryDataset(string $datasetId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'recovery' => $this->recovery->retryDataset($datasetId),
        ]);
    }
}
