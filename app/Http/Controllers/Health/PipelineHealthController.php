<?php

declare(strict_types=1);

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\HealthCheckRequest;
use App\Services\Pipeline\Health\PipelineHealthService;
use Illuminate\Http\JsonResponse;

class PipelineHealthController extends Controller
{
    public function __construct(
        private readonly PipelineHealthService $health,
    ) {}

    public function show(HealthCheckRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'checkedAt' => now()->toAtomString(),
            'checks' => $this->health->check($request->timeout() ?? 5),
        ]);
    }
}
