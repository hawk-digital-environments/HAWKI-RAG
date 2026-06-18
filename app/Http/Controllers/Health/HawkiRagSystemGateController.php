<?php

declare(strict_types=1);

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Services\Health\HawkiRagSystemGateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HawkiRagSystemGateController extends Controller
{
    public function show(Request $request, HawkiRagSystemGateService $gate): JsonResponse
    {
        $validated = $request->validate([
            'timeout' => 'nullable|integer|min:1|max:30',
        ]);

        return response()->json($gate->report(
            isset($validated['timeout']) ? (int) $validated['timeout'] : null,
        ));
    }
}
