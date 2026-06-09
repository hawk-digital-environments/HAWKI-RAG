<?php

namespace App\Http\Controllers;

use App\Services\Pipeline\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineHealthController extends Controller
{
    public function __construct(
        private readonly PipelineService $pipeline,
    ) {}

    public function queues(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'timeout' => 'nullable|integer|min:1|max:30',
        ]);

        return response()->json([
            'success' => true,
            'queueMonitor' => $this->pipeline->queues->status((int) ($validated['timeout'] ?? 5)),
        ]);
    }
}
