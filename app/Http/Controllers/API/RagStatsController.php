<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rag\DestroyQdrantCollectionRequest;
use App\Services\Rag\RagStatsService;
use Illuminate\Http\JsonResponse;

class RagStatsController extends Controller
{
    public function show(RagStatsService $stats): JsonResponse
    {
        return response()->json($stats->show());
    }

    public function destroyQdrantCollection(
        DestroyQdrantCollectionRequest $request,
        RagStatsService $stats,
    ): JsonResponse {
        $result = $stats->deleteQdrantCollection($request->collection());

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }
}
