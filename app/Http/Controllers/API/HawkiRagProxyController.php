<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rag\ListAuthorizedQueryDatasetsRequest;
use App\Http\Requests\Rag\QueryDatasetRequest;
use App\Services\Authorization\DatasetQueryAuthorizationService;
use App\Services\Rag\RagProxyService;
use Illuminate\Http\JsonResponse;

class HawkiRagProxyController extends Controller
{
    public function query(QueryDatasetRequest $request, RagProxyService $proxy): JsonResponse
    {
        $result = $proxy->query($request->authenticatedUser(), $request->validated());

        return response()->json($result['payload'], $result['status']);
    }

    public function datasets(
        ListAuthorizedQueryDatasetsRequest $request,
        DatasetQueryAuthorizationService $authorization,
    ): JsonResponse {
        return response()->json([
            'datasets' => $authorization->authorizedDatasets($request->authenticatedUser()),
        ]);
    }
}
