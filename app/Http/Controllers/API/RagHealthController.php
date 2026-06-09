<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Rag\RagHealthService;
use Illuminate\Http\JsonResponse;

class RagHealthController extends Controller
{
    public function show(RagHealthService $health): JsonResponse
    {
        $result = $health->show();

        return response()->json($result['payload'], $result['status']);
    }
}
