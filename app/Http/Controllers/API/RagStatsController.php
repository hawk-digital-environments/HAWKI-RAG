<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Rag\RagStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RagStatsController extends Controller
{
    public function show(RagStatsService $stats): JsonResponse
    {
        return response()->json($stats->show());
    }

    public function destroyQdrantCollection(Request $request, RagStatsService $stats, string $collection): JsonResponse
    {
        $result = $stats->deleteQdrantCollection(rawurldecode($collection));

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }
}
