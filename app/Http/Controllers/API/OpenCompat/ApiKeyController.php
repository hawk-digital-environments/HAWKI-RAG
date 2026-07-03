<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\OpenCompat;

use App\Http\Controllers\Controller;
use App\Services\OpenCompat\OpenCompatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    public function __construct(private readonly OpenCompatService $compat) {}

    public function list(): JsonResponse
    {
        $result = $this->compat->apiKeys();

        return response()->json($result['payload'], $result['status']);
    }

    public function save(Request $request): JsonResponse
    {
        $input = $request->validate([
            'provider' => 'required|string|max:80',
            'api_key' => 'required|string|max:2000',
            'apiKey' => 'sometimes|string|max:2000',
            'api_url' => 'sometimes|url|max:500',
            'apiUrl' => 'sometimes|url|max:500',
        ]);
        $result = $this->compat->saveApiKey($input);

        return response()->json($result['payload'], $result['status']);
    }
}
