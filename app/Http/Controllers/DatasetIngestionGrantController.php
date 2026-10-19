<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Dataset\GrantSelfDatasetIngestionAccessRequest;
use App\Services\Authorization\DatasetIngestionGrantService;
use Illuminate\Http\JsonResponse;

final class DatasetIngestionGrantController extends Controller
{
    public function store(
        GrantSelfDatasetIngestionAccessRequest $request,
        DatasetIngestionGrantService $grants,
        string $datasetId,
    ): JsonResponse {
        $grant = $grants->grantSelf($request->authenticatedUser(), $datasetId, $request->grantToken());

        return response()->json([
            'success' => true,
            'dataset_id' => (string) $grant->grant->dataset_id,
            'ingest_access' => [
                'granted' => true,
                'permission' => (string) $grant->grant->permission,
            ],
            'replayed' => $grant->replayed,
        ]);
    }
}
