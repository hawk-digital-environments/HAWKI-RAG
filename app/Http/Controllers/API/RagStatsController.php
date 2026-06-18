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
        $collection = rawurldecode($collection);
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,190}\z/', $collection)) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid Qdrant collection name.',
            ], 422);
        }

        $result = $stats->deleteQdrantCollection($collection);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }
}
