<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Pipeline\ListFailedPipelineJobsRequest;
use App\Http\Requests\Pipeline\RetrySelectedPipelineJobsRequest;
use App\Services\Pipeline\PipelineService;
use Illuminate\Http\JsonResponse;

class PipelineRecoveryController extends Controller
{
    public function __construct(
        private readonly PipelineService $pipeline,
    ) {}

    public function failedJobs(ListFailedPipelineJobsRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'jobs' => $this->pipeline->recovery->failedJobs($request->filters()),
        ]);
    }

    public function retryJob(string $jobId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'recovery' => $this->pipeline->recovery->retryJob($jobId),
        ]);
    }

    public function retrySelected(RetrySelectedPipelineJobsRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'recovery' => $this->pipeline->recovery->retrySelected($request->jobIds()),
        ]);
    }

    public function retryAll(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'recovery' => $this->pipeline->recovery->retryAll(),
        ]);
    }

    public function retryTask(string $taskId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'recovery' => $this->pipeline->recovery->retryTask($taskId),
        ]);
    }

    public function retryHeap(string $heapId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'recovery' => $this->pipeline->recovery->retryHeap($heapId),
        ]);
    }
}
