<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Dataset\GrantSelfDatasetQueryAccessRequest;
use App\Services\Authorization\DatasetQueryGrantService;
use Illuminate\Http\JsonResponse;

final class DatasetQueryGrantController extends Controller
{
    public function store(
        GrantSelfDatasetQueryAccessRequest $request,
        DatasetQueryGrantService $grants,
        string $datasetId,
    ): JsonResponse {
        $grant = $grants->grantSelf($request->authenticatedUser(), $datasetId);

        return response()->json([
            'success' => true,
            'dataset_id' => (string) $grant->dataset_id,
            'query_access' => [
                'granted' => true,
                'permission' => (string) $grant->permission,
            ],
        ]);
    }
}
