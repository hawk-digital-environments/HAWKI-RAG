<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rag\ListAuthorizedQueryDatasetsRequest;
use App\Http\Requests\Rag\QueryDatasetRequest;
use App\Services\Dataset\DatasetAuthority;
use App\Services\Rag\RagQueryService;
use Illuminate\Http\JsonResponse;

class HawkiRagProxyController extends Controller
{
    public function query(QueryDatasetRequest $request, RagQueryService $proxy): JsonResponse
    {
        $result = $proxy->query($request->authenticatedUser(), $request->validated());

        return response()->json($result['payload'], $result['status']);
    }

    public function datasets(
        ListAuthorizedQueryDatasetsRequest $request,
        DatasetAuthority $datasets,
    ): JsonResponse {
        $result = $datasets->queryDatasets($request->authenticatedUser());

        return response()->json($result['payload'], $result['status']);
    }
}
