<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Authorization\ApiActorResolver;
use App\Services\Authorization\GatewaySearchFilterService;
use App\Services\Rag\RagProxyService;
use App\Services\Rag\RagQueryPayloadFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HawkiRagProxyController extends Controller
{
    public function query(
        Request $request,
        RagProxyService $proxy,
        ApiActorResolver $actors,
        GatewaySearchFilterService $filters,
        RagQueryPayloadFactory $payloads,
    ): JsonResponse
    {
        $data = $request->validate([
            'query'           => 'required|string|max:4000',
            'top_k'           => 'sometimes|integer|min:1|max:100',
            'filters'         => 'sometimes|array',
            'is_optimized'    => 'sometimes|boolean',
            'generate'        => 'sometimes|boolean',
            'fast_mode'       => 'sometimes|boolean',
            'smart_lookup'    => 'sometimes|boolean',
            'user_identifier' => 'sometimes|string|max:255',
            'preferred_tags'  => 'sometimes|array|max:20',
            'preferred_tags.*'=> 'string|max:80',
        ]);

        $actor = $actors->resolve($request);
        $data['filters'] = $filters->build($data['filters'] ?? [], $actor, $data['user_identifier'] ?? null);
        unset($data['user_identifier']);

        $result = $proxy->query($payloads->make($data));

        return response()->json($result['payload'], $result['status']);
    }
}
