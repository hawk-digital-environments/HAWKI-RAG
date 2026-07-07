<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Search\SearchQueryRequest;
use App\Services\Authorization\ApiActorResolver;
use App\Services\Authorization\GatewaySearchFilterService;
use App\Services\Rag\RagProxyService;
use App\Services\Rag\RagQueryPayloadFactory;
use Illuminate\Http\JsonResponse;

class HawkiRagProxyController extends Controller
{
    public function query(
        SearchQueryRequest $request,
        RagProxyService $proxy,
        ApiActorResolver $actors,
        GatewaySearchFilterService $filters,
        RagQueryPayloadFactory $payloads,
    ): JsonResponse
    {
        $data = $request->payload();
        $actor = $actors->resolve($request);
        $data['filters'] = $filters->build($data['filters'] ?? [], $actor, $data['user_identifier'] ?? null);
        unset($data['user_identifier']);

        $result = $proxy->query($payloads->make($data));

        return response()->json($result['payload'], $result['status']);
    }
}
