<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pipeline\ClearDirectIngestStatusRequest;
use App\Http\Requests\Pipeline\ShowDirectIngestStatusRequest;
use App\Services\Pipeline\DirectIngest\DirectIngestStatusService;
use App\Services\Pipeline\Values\DirectIngestActionResult;
use Illuminate\Http\JsonResponse;

class IngestStatusController extends Controller
{
    public function show(ShowDirectIngestStatusRequest $request, DirectIngestStatusService $statuses): JsonResponse
    {
        return $this->respond($statuses->show($request->mode()));
    }

    public function clear(ClearDirectIngestStatusRequest $request, DirectIngestStatusService $statuses): JsonResponse
    {
        return $this->respond($statuses->clear($request->mode()));
    }

    private function respond(DirectIngestActionResult $result): JsonResponse
    {
        return response()->json($result->payload, $result->status);
    }
}
