<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\OpenCompat;

use App\Http\Controllers\Controller;
use App\Services\OpenCompat\OpenCompatService;
use Illuminate\Http\JsonResponse;

class ModelController extends Controller
{
    public function __construct(private readonly OpenCompatService $compat) {}

    public function list(): JsonResponse
    {
        $result = $this->compat->models();

        return response()->json($result['payload'], $result['status']);
    }

    public function unsupported(): JsonResponse
    {
        $result = $this->compat->unsupported(
            'models/write',
            'RAWKI exposes configured runtime models from settings, but does not support custom model lifecycle endpoints.',
        );

        return response()->json($result['payload'], $result['status']);
    }
}
