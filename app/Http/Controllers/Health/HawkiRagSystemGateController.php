<?php

declare(strict_types=1);

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\HealthCheckRequest;
use App\Services\Health\HawkiRagSystemGateService;
use Illuminate\Http\JsonResponse;

class HawkiRagSystemGateController extends Controller
{
    public function show(HealthCheckRequest $request, HawkiRagSystemGateService $gate): JsonResponse
    {
        return response()->json($gate->report($request->timeout()));
    }
}
