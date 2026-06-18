<?php

declare(strict_types=1);

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Services\Rag\RagMonitorService;
use Illuminate\Http\JsonResponse;

class RagMonitorController extends Controller
{
    public function show(RagMonitorService $monitor): JsonResponse
    {
        return response()->json($monitor->show());
    }
}
