<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pipeline;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pipeline\StorePipelineWorkerEventRequest;
use App\Services\Pipeline\PipelineWorkerEventService;
use Illuminate\Http\JsonResponse;

class PipelineWorkerEventController extends Controller
{
    public function __invoke(
        StorePipelineWorkerEventRequest $request,
        PipelineWorkerEventService $events,
    ): JsonResponse {
        $receipt = $events->record($request->event());

        return response()->json($receipt, $receipt['duplicate'] ? 200 : 202);
    }
}
