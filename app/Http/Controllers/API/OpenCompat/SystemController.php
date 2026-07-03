<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\OpenCompat;

use App\Http\Controllers\Controller;
use App\Services\OpenCompat\OpenCompatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemController extends Controller
{
    public function __construct(private readonly OpenCompatService $compat) {}

    public function migrateDocument(): JsonResponse
    {
        $result = $this->compat->migrateDocument();

        return response()->json($result['payload'], $result['status']);
    }

    public function logs(Request $request): JsonResponse
    {
        $result = $this->compat->logs((int) $request->query('limit', 100));

        return response()->json($result['payload'], $result['status']);
    }

    public function usage(): JsonResponse
    {
        $result = $this->compat->usage();

        return response()->json($result['payload'], $result['status']);
    }
}
