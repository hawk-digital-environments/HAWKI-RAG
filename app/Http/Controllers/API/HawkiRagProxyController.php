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
use Illuminate\Validation\ValidationException;

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
            'limit'           => 'sometimes|integer|min:1|max:100',
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

        if (isset($data['limit'], $data['top_k']) && (int) $data['limit'] !== (int) $data['top_k']) {
            throw ValidationException::withMessages([
                'limit' => ['Provide only one search limit value. limit and top_k must match when both are sent.'],
            ]);
        }

        if (! isset($data['limit']) && isset($data['top_k'])) {
            $data['limit'] = (int) $data['top_k'];
        }

        unset($data['top_k']);

        $actor = $actors->resolve($request);
        $data['filters'] = $filters->build($data['filters'] ?? [], $actor, $data['user_identifier'] ?? null);
        unset($data['user_identifier']);

        $result = $proxy->query($payloads->make($data));

        return response()->json($result['payload'], $result['status']);
    }
}
