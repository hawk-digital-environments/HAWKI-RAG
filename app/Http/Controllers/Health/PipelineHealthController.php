<?php

declare(strict_types=1);

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Services\Pipeline\Health\PipelineHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PipelineHealthController extends Controller
{
    public function __construct(
        private readonly PipelineHealthService $health,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'timeout' => 'nullable|integer|min:1|max:30',
        ]);

        return response()->json([
            'success' => true,
            'checkedAt' => now()->toAtomString(),
            'checks' => $this->health->check((int) ($validated['timeout'] ?? 5)),
        ]);
    }
}
