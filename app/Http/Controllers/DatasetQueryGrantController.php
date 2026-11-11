<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Dataset\GrantSelfDatasetQueryAccessRequest;
use App\Services\Dataset\DatasetAuthority;
use Illuminate\Http\JsonResponse;

final class DatasetQueryGrantController extends Controller
{
    public function store(
        GrantSelfDatasetQueryAccessRequest $request,
        DatasetAuthority $datasets,
        string $datasetId,
    ): JsonResponse {
        $result = $datasets->grantQuerySelf($request->authenticatedUser(), $datasetId);

        return response()->json($result['payload'], $result['status']);
    }
}
