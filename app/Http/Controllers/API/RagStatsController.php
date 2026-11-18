<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Rag\RagStatsService;
use Illuminate\Http\JsonResponse;

class RagStatsController extends Controller
{
    public function show(RagStatsService $stats): JsonResponse
    {
        $result = $stats->show();

        return response()->json($result, (int) ($result['status'] ?? 200));
    }

    public function destroyQdrantCollection(
    ): JsonResponse {
        return response()->json(['ok' => false, 'error' => 'collection_delete_removed',
            'message' => 'Use DELETE /api/datasets/{id}/storage for authoritative dataset storage deletion.'], 410);
    }
}
