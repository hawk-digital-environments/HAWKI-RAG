<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Rag\RagProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HawkiRagProxyController extends Controller
{
    public function query(Request $request, RagProxyService $proxy): JsonResponse
    {
        $data = $request->validate([
            'query'           => 'required|string|max:4000',
            'top_k'           => 'sometimes|integer|min:1|max:100',
            'is_optimized'    => 'sometimes|boolean',
            'generate'        => 'sometimes|boolean',
            'fast_mode'       => 'sometimes|boolean',
            'smart_lookup'    => 'sometimes|boolean',
            'preferred_tags'  => 'sometimes|array|max:20',
            'preferred_tags.*'=> 'string|max:80',
        ]);

        $result = $proxy->query($data);

        return response()->json($result['payload'], $result['status']);
    }
}
