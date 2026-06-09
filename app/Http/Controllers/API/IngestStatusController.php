<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pipeline\ClearDirectIngestStatusRequest;
use App\Http\Requests\Pipeline\ShowDirectIngestStatusRequest;
use App\Services\Pipeline\PipelineService;
use App\Services\DirectIngest\Values\DirectIngestActionResult;
use Illuminate\Http\JsonResponse;

class IngestStatusController extends Controller
{
    public function __construct(
        private readonly PipelineService $pipeline,
    ) {}

    public function show(ShowDirectIngestStatusRequest $request): JsonResponse
    {
        return $this->respond($this->pipeline->directIngestStatuses->show($request->mode()));
    }

    public function clear(ClearDirectIngestStatusRequest $request): JsonResponse
    {
        return $this->respond($this->pipeline->directIngestStatuses->clear($request->mode()));
    }

    private function respond(DirectIngestActionResult $result): JsonResponse
    {
        return response()->json($result->payload, $result->status);
    }
}
