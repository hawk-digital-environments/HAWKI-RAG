<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Pipeline\ListFailedPipelineJobsRequest;
use App\Http\Requests\Pipeline\RetrySelectedPipelineJobsRequest;
use App\Services\Pipeline\PipelineRecoveryService;
use Illuminate\Http\JsonResponse;

class PipelineRecoveryController extends Controller
{
    public function __construct(
        private readonly PipelineRecoveryService $recovery,
    ) {
    }

    public function failedJobs(ListFailedPipelineJobsRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'jobs' => $this->recovery->failedJobs($request->filters()),
        ]);
    }

    public function retryJob(string $jobId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'recovery' => $this->recovery->retryJob($jobId),
        ]);
    }

    public function retrySelected(RetrySelectedPipelineJobsRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'recovery' => $this->recovery->retrySelected($request->jobIds()),
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
