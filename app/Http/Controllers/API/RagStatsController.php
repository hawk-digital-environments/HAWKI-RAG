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
        return response()->json($stats->show());
    }
}
