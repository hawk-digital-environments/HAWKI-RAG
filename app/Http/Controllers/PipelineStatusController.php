<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Pipeline\PipelineService;
use Illuminate\Http\JsonResponse;

class PipelineStatusController extends Controller
{
    public function __construct(
        private readonly PipelineService $pipeline,
    ) {}

    public function show(string $jobId): JsonResponse
    {
        return response()->json($this->pipeline->status->show($jobId));
    }
}
